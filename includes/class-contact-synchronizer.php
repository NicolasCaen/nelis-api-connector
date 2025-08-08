<?php
/**
 * Classe de synchronisation complète Nelis <-> Brevo
 */
class ContactSynchronizer {
    private $nelis_client;
    private $brevo_connector;
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'nelis_brevo_sync';
        $this->nelis_client = new Nelis_API_Client();
        $this->brevo_connector = new BrevoConnector();
    }
    
    /**
     * Créer la table de synchronisation
     */
    public function create_sync_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $this->table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            nelis_id int(11) NOT NULL,
            email varchar(255) NOT NULL,
            firstname varchar(255),
            lastname varchar(255),
            data_hash varchar(64) NOT NULL,
            brevo_status enum('pending','synced','error') DEFAULT 'pending',
            error_message text,
            last_sync datetime,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY nelis_id (nelis_id),
            KEY email (email),
            KEY brevo_status (brevo_status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Synchronisation complète (première fois)
     */
    public function full_sync() {
        $this->log("Début synchronisation complète");
        
        // Vider la table locale
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE $this->table_name");
        
        // Récupérer tous les contacts par batch de 100
        $range_start = 0;
        $batch_size = 100;
        $total_processed = 0;
        
        do {
            $contacts = $this->get_nelis_contacts_range($range_start, $range_start + $batch_size - 1);
            
            if (!$contacts || !is_array($contacts) || empty($contacts)) {
                break;
            }
            
            foreach ($contacts as $contact) {
                if ($this->process_nelis_contact($contact)) {
                    $total_processed++;
                }
            }
            
            $range_start += $batch_size;
            
        } while (count($contacts) == $batch_size);
        
        // Synchroniser vers Brevo
        $synced = $this->sync_to_brevo();
        
        $this->log("Synchronisation complète terminée: $total_processed contacts traités, $synced synchronisés vers Brevo");
        return $total_processed;
    }
    
    /**
     * Synchronisation incrémentielle
     */
    public function incremental_sync() {
        $this->log("Début synchronisation incrémentielle");
        
        $last_sync = get_option('nelis_brevo_last_sync', date('Y-m-d', strtotime('-1 day')));
        
        $total_processed = 0;
        
        // Contacts créés
        $created_contacts = $this->nelis_client->get_contacts_created_since($last_sync);
        if ($created_contacts && is_array($created_contacts)) {
            foreach ($created_contacts as $contact) {
                if ($this->process_nelis_contact($contact)) {
                    $total_processed++;
                }
            }
        }
        
        // Contacts modifiés
        $updated_contacts = $this->nelis_client->get_contacts_updated_since($last_sync);
        if ($updated_contacts && is_array($updated_contacts)) {
            foreach ($updated_contacts as $contact) {
                if ($this->process_nelis_contact($contact, true)) {
                    $total_processed++;
                }
            }
        }
        
        // Synchroniser vers Brevo
        $synced = $this->sync_to_brevo();
        
        // Mettre à jour la date de dernière sync
        update_option('nelis_brevo_last_sync', current_time('mysql'));
        
        $this->log("Synchronisation incrémentielle terminée: $total_processed contacts traités, $synced synchronisés vers Brevo");
    }
    
    /**
     * Récupérer les contacts Nelis par range
     */
    private function get_nelis_contacts_range($start, $end) {
        // Obtenir le token d'accès
        if (!$this->nelis_client->get_access_token()) {
            $this->log("Erreur: Impossible d'obtenir le token d'accès Nelis");
            return false;
        }
        
        $url = "https://amavea.mynelis.com/api/v4/people?groups=12&range=$start-$end&with_custom_values=true&fields=id,email,lastname,firstname";
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->nelis_client->access_token,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 30
        );
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            $this->log("Erreur cURL Nelis: " . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $this->log("Erreur API Nelis ($response_code) pour range $start-$end");
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("Erreur JSON Nelis: " . json_last_error_msg());
            return false;
        }
        
        return $data;
    }
    
    /**
     * Traiter un contact Nelis
     */
    private function process_nelis_contact($contact, $is_update = false) {
        global $wpdb;
        
        if (!isset($contact['id']) || !isset($contact['email']) || empty($contact['email'])) {
            $this->log("Contact ignoré - ID ou email manquant: " . json_encode($contact));
            return false;
        }
        
        $data_hash = $this->generate_contact_hash($contact);
        
        $contact_data = [
            'nelis_id' => $contact['id'],
            'email' => $contact['email'],
            'firstname' => $contact['firstname'] ?? '',
            'lastname' => $contact['lastname'] ?? '',
            'data_hash' => $data_hash,
            'brevo_status' => 'pending',
            'error_message' => null
        ];
        
        if ($is_update) {
            // Vérifier si le hash a changé
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT data_hash FROM $this->table_name WHERE nelis_id = %d", 
                $contact['id']
            ));
            
            if ($existing && $existing->data_hash === $data_hash) {
                return false; // Pas de changement
            }
            
            $result = $wpdb->update(
                $this->table_name,
                $contact_data,
                ['nelis_id' => $contact['id']]
            );
        } else {
            // Vérifier si le contact existe déjà
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $this->table_name WHERE nelis_id = %d", 
                $contact['id']
            ));
            
            if ($existing) {
                // Mettre à jour au lieu d'insérer
                $result = $wpdb->update(
                    $this->table_name,
                    $contact_data,
                    ['nelis_id' => $contact['id']]
                );
            } else {
                $result = $wpdb->insert($this->table_name, $contact_data);
            }
        }
        
        if ($result === false) {
            $this->log("Erreur DB pour contact {$contact['email']}: " . $wpdb->last_error);
            return false;
        }
        
        return true;
    }
    
    /**
     * Synchroniser vers Brevo
     */
    public function sync_to_brevo() {
        global $wpdb;
        
        $contacts_to_sync = $wpdb->get_results(
            "SELECT * FROM $this->table_name WHERE brevo_status = 'pending' LIMIT 50"
        );
        
        $synced_count = 0;
        
        foreach ($contacts_to_sync as $contact) {
            try {
                $attributes = [
                    'FIRSTNAME' => $contact->firstname ?: '',
                    'LASTNAME' => $contact->lastname ?: '',
                    'NELIS_ID' => $contact->nelis_id
                ];
                
                $success = $this->brevo_connector->addContactToList(
                    $contact->email, 
                    null, 
                    $attributes
                );
                
                if ($success) {
                    $wpdb->update(
                        $this->table_name,
                        [
                            'brevo_status' => 'synced',
                            'last_sync' => current_time('mysql'),
                            'error_message' => null
                        ],
                        ['id' => $contact->id]
                    );
                    $synced_count++;
                    $this->log("Contact synchronisé: {$contact->email}");
                }
                
            } catch (Exception $e) {
                $error_message = $e->getMessage();
                $wpdb->update(
                    $this->table_name,
                    [
                        'brevo_status' => 'error',
                        'error_message' => $error_message
                    ],
                    ['id' => $contact->id]
                );
                
                $this->log("Erreur sync Brevo pour contact {$contact->email}: " . $error_message);
            }
            
            // Petit délai pour éviter de surcharger l'API Brevo
            usleep(100000); // 0.1 seconde
        }
        
        return $synced_count;
    }
    
    /**
     * Générer un hash pour détecter les changements
     */
    private function generate_contact_hash($contact) {
        $data = [
            'email' => $contact['email'] ?? '',
            'firstname' => $contact['firstname'] ?? '',
            'lastname' => $contact['lastname'] ?? ''
        ];
        
        return hash('sha256', serialize($data));
    }
    
    /**
     * Logger les messages
     */
    private function log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log("[Nelis-Brevo Sync] " . $message);
        }
    }
    
    /**
     * Obtenir les statistiques de synchronisation
     */
    public function get_sync_stats() {
        global $wpdb;
        
        return [
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name"),
            'synced' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name WHERE brevo_status = 'synced'"),
            'pending' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name WHERE brevo_status = 'pending'"),
            'errors' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name WHERE brevo_status = 'error'")
        ];
    }
    
    /**
     * Remettre les contacts en erreur en statut pending
     */
    public function retry_failed_contacts() {
        global $wpdb;
        
        $updated = $wpdb->update(
            $this->table_name,
            [
                'brevo_status' => 'pending',
                'error_message' => null
            ],
            ['brevo_status' => 'error']
        );
        
        $this->log("$updated contacts remis en file d'attente");
        
        // Relancer la synchronisation
        return $this->sync_to_brevo();
    }
    
    /**
     * Nettoyer les anciens contacts (optionnel)
     */
    public function cleanup_old_contacts($days = 30) {
        global $wpdb;
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM $this->table_name WHERE brevo_status = 'synced' AND last_sync < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
        
        $this->log("$deleted anciens contacts nettoyés (plus de $days jours)");
        return $deleted;
    }
}
