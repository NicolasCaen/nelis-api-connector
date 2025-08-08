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
    /**
     * Créer la table de synchronisation
     */
    public function create_sync_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $this->table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            nelis_id int(11),
            email varchar(255) NOT NULL,
            firstname varchar(255),
            lastname varchar(255),
            date_creation datetime,
            date_update datetime,
            nelis_hash varchar(32),
            brevo_status varchar(20) DEFAULT 'pending',
            error_message text,
            last_sync datetime,
            PRIMARY KEY (id),
            UNIQUE KEY email (email),
            KEY brevo_status (brevo_status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        $this->log("Table de synchronisation créée ou mise à jour");
    }
    
    /**
     * Vérifie si la table de synchronisation existe
     * 
     * @return bool True si la table existe, false sinon
     */
    public function table_exists() {
        global $wpdb;
        
        $result = $wpdb->query("SHOW TABLES LIKE '$this->table_name'");
        return $result > 0;
    }
    
    /**
     * Synchronisation complète (première fois)
     */
    /**
     * Vérifie si les colonnes date_creation et date_update existent et les ajoute si nécessaire
     */
    private function check_date_columns() {
        global $wpdb;
        
        $this->log("Vérification des colonnes de dates");
        
        // Vérifier si la colonne date_creation existe
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $this->table_name LIKE 'date_creation'");
        if (empty($column_exists)) {
            $this->log("Ajout de la colonne date_creation");
            $wpdb->query("ALTER TABLE $this->table_name ADD COLUMN date_creation datetime");
        }
        
        // Vérifier si la colonne date_update existe
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $this->table_name LIKE 'date_update'");
        if (empty($column_exists)) {
            $this->log("Ajout de la colonne date_update");
            $wpdb->query("ALTER TABLE $this->table_name ADD COLUMN date_update datetime");
        }
    }
    
    /**
     * Vide la table de synchronisation
     */
    private function truncate_sync_table() {
        global $wpdb;
        
        $this->log("Vidage de la table de synchronisation");
        $wpdb->query("TRUNCATE TABLE $this->table_name");
    }
    
    /**
     * Synchronisation complète (première fois)
     */
    public function full_sync() {
        $this->log("Début synchronisation complète");
        
        // Créer la table si elle n'existe pas
        $this->create_sync_table();
        
        // Vérifier si les colonnes date_creation et date_update existent
        $this->check_date_columns();
        
        // Vider la table avant synchronisation complète
        $this->truncate_sync_table();
        
        $batch_size = 100;
        $start = 0;
        $total_contacts = 0;
        $max_iterations = 100; // Augmenter pour gérer plus de contacts
        $iteration = 0;
        
        // Sauvegarder les réponses JSON brutes pour analyse
        $temp_dir = WP_CONTENT_DIR . '/temp';
        if (!file_exists($temp_dir)) {
            mkdir($temp_dir, 0755, true);
        }
        
        do {
            $end = $start + $batch_size - 1;
            $this->log("Récupération contacts Nelis $start-$end (itération $iteration)");
            
            $contacts = $this->get_nelis_contacts_range($start, $end);
            
            if ($contacts === false) {
                $this->log("Erreur lors de la récupération des contacts");
                break;
            }
            
            // Sauvegarder la réponse JSON brute
            $json_file = $temp_dir . "/nelis_contacts_" . $start . "-" . $end . ".json";
            file_put_contents($json_file, json_encode($contacts, JSON_PRETTY_PRINT));
            $this->log("Réponse JSON sauvegardée dans $json_file");
            
            $count = count($contacts);
            $this->log("$count contacts récupérés dans cette tranche");
            
            if ($count > 0) {
                foreach ($contacts as $contact) {
                    $result = $this->process_nelis_contact($contact);
                    if ($result) {
                        $total_contacts++;
                    }
                }
                $this->log("Total contacts traités jusqu'à présent: $total_contacts");
            }
            
            $start += $batch_size;
            $iteration++;
            
            // Continuer tant qu'on reçoit des contacts et qu'on n'a pas atteint la limite d'itérations
        } while ($count > 0 && $iteration < $max_iterations);
        
        $this->log("Synchronisation complète terminée: $total_contacts contacts traités en $iteration itérations");
        
        // Synchroniser vers Brevo par lots
        $synced = $this->sync_to_brevo();
        $this->log("$synced contacts synchronisés vers Brevo");
        
        return $total_contacts;
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
        
        // Retirer le paramètre groups pour récupérer tous les contacts
        $url = "https://amavea.mynelis.com/api/v4/people?range=$start-$end&with_custom_values=true";
        
        $this->log("Appel API Nelis: $url");
        
        $access_token = $this->nelis_client->get_access_token();
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 60 // Augmenter le timeout pour les grands volumes
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
        
        // Enregistrer la réponse brute dans un fichier temporaire pour analyse
        $temp_dir = plugin_dir_path(dirname(__FILE__)) . 'temp/';
        if (!file_exists($temp_dir)) {
            mkdir($temp_dir, 0755, true);
        }
        $temp_file = $temp_dir . 'nelis_response_' . date('Y-m-d_H-i-s') . '.json';
        file_put_contents($temp_file, $body);
        $this->log("Réponse Nelis enregistrée dans $temp_file");
        
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
    /**
     * Vérifie si une colonne existe dans la table
     */
    private function column_exists($column_name) {
        global $wpdb;
        $check_column = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME,
            $this->table_name,
            $column_name
        ));
        return !empty($check_column);
    }
    
    /**
     * Ajoute une colonne à la table si elle n'existe pas
     */
    private function add_column_if_not_exists($column_name, $column_definition) {
        global $wpdb;
        if (!$this->column_exists($column_name)) {
            $wpdb->query("ALTER TABLE $this->table_name ADD COLUMN $column_name $column_definition");
            $this->log("Colonne $column_name ajoutée à la table $this->table_name");
            return true;
        }
        return false;
    }
    
    private function process_nelis_contact($contact, $is_update = false) {
        global $wpdb;
        
        if (!isset($contact['id']) || !isset($contact['email']) || empty($contact['email'])) {
            $this->log("Contact ignoré - ID ou email manquant: " . json_encode($contact));
            return false;
        }
        
        $data_hash = $this->generate_contact_hash($contact);
        
        // S'assurer que les colonnes date_creation et date_update existent
        $this->add_column_if_not_exists('date_creation', 'DATETIME');
        $this->add_column_if_not_exists('date_update', 'DATETIME');
        
        $contact_data = [
            'nelis_id' => $contact['id'],
            'email' => $contact['email'],
            'firstname' => $contact['firstname'] ?? '',
            'lastname' => $contact['lastname'] ?? '',
            'data_hash' => $data_hash,
            'brevo_status' => 'pending',
            'error_message' => null
        ];
        
        // Ajouter les dates si elles existent dans les données du contact
        if (isset($contact['date_creation'])) {
            $contact_data['date_creation'] = date('Y-m-d H:i:s', strtotime($contact['date_creation']));
        }
        
        if (isset($contact['date_update'])) {
            $contact_data['date_update'] = date('Y-m-d H:i:s', strtotime($contact['date_update']));
        }
        
        // Traiter les champs personnalisés
        if (isset($contact['customfieldsvalues']) && is_array($contact['customfieldsvalues'])) {
            foreach ($contact['customfieldsvalues'] as $field_id => $field_value) {
                $column_name = 'custom_' . $field_id;
                
                // Ajouter la colonne si elle n'existe pas
                $this->add_column_if_not_exists($column_name, 'TEXT');
                
                // Ajouter la valeur aux données du contact
                $contact_data[$column_name] = $field_value;
            }
        }
        
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
    /**
     * Synchronise les contacts en attente vers Brevo
     * 
     * @return int Nombre de contacts synchronisés
     */
    public function sync_to_brevo() {
        global $wpdb;
        
        // Vérifier que la table existe
        if (!$this->table_exists()) {
            $this->log("La table de synchronisation n'existe pas. Création...");
            $this->create_sync_table();
        }
        
        // Récupérer les contacts en attente de synchronisation
        $contacts_to_sync = $wpdb->get_results(
            "SELECT * FROM $this->table_name WHERE brevo_status = 'pending' LIMIT 50"
        );
        
        if (empty($contacts_to_sync)) {
            $this->log("Aucun contact à synchroniser avec Brevo");
            return 0;
        }
        
        $this->log("Synchronisation de " . count($contacts_to_sync) . " contacts vers Brevo");
        $synced_count = 0;
        
        foreach ($contacts_to_sync as $contact) {
            try {
                // Attributs de base
                $attributes = [
                    'FIRSTNAME' => $contact->firstname ?: '',
                    'LASTNAME' => $contact->lastname ?: '',
                    'NELIS_ID' => $contact->nelis_id
                ];
                
                // Ajouter les dates si elles existent
                if (!empty($contact->date_creation)) {
                    $attributes['DATE_CREATION'] = $contact->date_creation;
                }
                
                if (!empty($contact->date_update)) {
                    $attributes['DATE_UPDATE'] = $contact->date_update;
                }
                
                // Récupérer tous les champs personnalisés
                $columns = $wpdb->get_results("SHOW COLUMNS FROM $this->table_name LIKE 'custom_%'");
                if (!empty($columns)) {
                    foreach ($columns as $column) {
                        $field_name = $column->Field;
                        if (property_exists($contact, $field_name) && !empty($contact->$field_name)) {
                            // Convertir le nom de colonne custom_XX en CUSTOM_XX pour Brevo
                            $brevo_field_name = strtoupper($field_name);
                            $attributes[$brevo_field_name] = $contact->$field_name;
                        }
                    }
                }
                
                $this->log("Envoi à Brevo pour {$contact->email} avec attributs: " . json_encode($attributes));
                
                // Sauvegarder les attributs envoyés à Brevo pour débogage
                $temp_dir = WP_CONTENT_DIR . '/temp';
                if (!file_exists($temp_dir)) {
                    mkdir($temp_dir, 0755, true);
                }
                $json_file = $temp_dir . "/brevo_attributes_{$contact->email}_" . date('Y-m-d_H-i-s') . ".json";
                file_put_contents($json_file, json_encode($attributes, JSON_PRETTY_PRINT));
                
                $success = $this->brevo_connector->addContactToList(
                    $contact->email, 
                    null, 
                    $attributes
                );
                
                if ($success) {
                    // Vérifier que le contact est bien présent dans la liste Brevo
                    $is_in_list = $this->brevo_connector->isContactInList($contact->email);
                    
                    if ($is_in_list) {
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
                        $this->log("Contact synchronisé et vérifié dans la liste Brevo: {$contact->email}");
                    } else {
                        // Le contact a été ajouté mais n'est pas trouvé dans la liste
                        $wpdb->update(
                            $this->table_name,
                            [
                                'brevo_status' => 'error',
                                'error_message' => 'Contact ajouté mais non trouvé dans la liste Brevo'
                            ],
                            ['id' => $contact->id]
                        );
                        $this->log("Contact {$contact->email} ajouté mais non trouvé dans la liste Brevo");
                    }
                } else {
                    // Mettre à jour le statut en erreur
                    $wpdb->update(
                        $this->table_name,
                        [
                            'brevo_status' => 'error',
                            'error_message' => 'Erreur lors de la synchronisation avec Brevo'
                        ],
                        ['id' => $contact->id]
                    );
                    $this->log("Erreur lors de la synchronisation du contact {$contact->email} avec Brevo");
                }
            } catch (Exception $e) {
                // Mettre à jour le statut en erreur avec le message d'erreur
                $wpdb->update(
                    $this->table_name,
                    [
                        'brevo_status' => 'error',
                        'error_message' => $e->getMessage()
                    ],
                    ['id' => $contact->id]
                );
                $this->log("Exception lors de la synchronisation du contact {$contact->email}: " . $e->getMessage());
            }
            
            // Petit délai pour éviter de surcharger l'API Brevo
            usleep(100000); // 0.1 seconde
        }
        
        $this->log("Synchronisation terminée: $synced_count contacts synchronisés sur " . count($contacts_to_sync));
        return $synced_count;
    }
    
    /**
     * Générer un hash pour détecter les changements
     */
    private function generate_contact_hash($contact) {
        $data = [
            'email' => $contact['email'] ?? '',
            'firstname' => $contact['firstname'] ?? '',
            'lastname' => $contact['lastname'] ?? '',
            'date_creation' => $contact['date_creation'] ?? '',
            'date_update' => $contact['date_update'] ?? ''
        ];
        
        // Ajouter les champs personnalisés au hash
        if (isset($contact['customfieldsvalues']) && is_array($contact['customfieldsvalues'])) {
            foreach ($contact['customfieldsvalues'] as $field_id => $field_value) {
                $data['custom_' . $field_id] = $field_value;
            }
        }
        
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
        
        $this->log("$deleted anciens contacts supprimés");
        return $deleted;
    }
    
    /**
     * Resynchronise les contacts en erreur vers Brevo
     * 
     * @return int Nombre de contacts resynchronisés
     */
    public function resync_errors() {
        global $wpdb;
        
        $this->log("Début resynchronisation des contacts en erreur");
        
        // Vérifier que la table existe
        if (!$this->table_exists()) {
            $this->log("La table de synchronisation n'existe pas. Création...");
            $this->create_sync_table();
            return 0;
        }
        
        // Marquer tous les contacts en erreur comme 'pending' pour les resynchroniser
        $updated = $wpdb->query(
            "UPDATE $this->table_name SET brevo_status = 'pending', error_message = NULL WHERE brevo_status = 'error'"
        );
        
        $this->log("$updated contacts marqués pour resynchronisation");
        
        if ($updated > 0) {
            // Synchroniser vers Brevo
            $synced = $this->sync_to_brevo();
            $this->log("Resynchronisation terminée: $synced contacts resynchronisés sur $updated");
            return $synced;
        }
        
        $this->log("Aucun contact en erreur à resynchroniser");
        return 0;
    }
    
    /**
     * Vérifie le statut des contacts dans Brevo et met à jour leur statut local
     * 
     * @return array Statistiques de vérification
     */
    public function verify_brevo_status() {
        global $wpdb;
        
        $this->log("Début vérification des statuts Brevo");
        
        // Vérifier que la table existe
        if (!$this->table_exists()) {
            $this->log("La table de synchronisation n'existe pas. Création...");
            $this->create_sync_table();
            return ['verified' => 0, 'errors' => 0, 'fixed' => 0];
        }
        
        // Récupérer tous les contacts marqués comme synchronisés
        $contacts = $wpdb->get_results(
            "SELECT * FROM $this->table_name WHERE brevo_status = 'synced' LIMIT 100"
        );
        
        if (empty($contacts)) {
            $this->log("Aucun contact synchronisé à vérifier");
            return ['verified' => 0, 'errors' => 0, 'fixed' => 0];
        }
        
        $this->log("Vérification de " . count($contacts) . " contacts dans Brevo");
        
        $stats = [
            'verified' => 0,
            'errors' => 0,
            'fixed' => 0
        ];
        
        foreach ($contacts as $contact) {
            // Vérifier si le contact est bien dans la liste Brevo
            $is_in_list = $this->brevo_connector->isContactInList($contact->email);
            
            if ($is_in_list) {
                // Contact correctement synchronisé
                $stats['verified']++;
            } else {
                // Contact marqué comme synchronisé mais non trouvé dans Brevo
                $stats['errors']++;
                
                // Essayer de le resynchroniser
                $attributes = [
                    'FIRSTNAME' => $contact->firstname ?: '',
                    'LASTNAME' => $contact->lastname ?: '',
                    'NELIS_ID' => $contact->nelis_id
                ];
                
                // Ajouter les dates si elles existent
                if (!empty($contact->date_creation)) {
                    $attributes['DATE_CREATION'] = $contact->date_creation;
                }
                
                if (!empty($contact->date_update)) {
                    $attributes['DATE_UPDATE'] = $contact->date_update;
                }
                
                // Ajouter les champs personnalisés
                $columns = $wpdb->get_results("SHOW COLUMNS FROM $this->table_name LIKE 'custom_%'");
                if (!empty($columns)) {
                    foreach ($columns as $column) {
                        $field_name = $column->Field;
                        if (property_exists($contact, $field_name) && !empty($contact->$field_name)) {
                            $brevo_field_name = strtoupper($field_name);
                            $attributes[$brevo_field_name] = $contact->$field_name;
                        }
                    }
                }
                
                $success = $this->brevo_connector->addContactToList(
                    $contact->email, 
                    null, 
                    $attributes
                );
                
                if ($success && $this->brevo_connector->isContactInList($contact->email)) {
                    // Contact resynchronisé avec succès
                    $wpdb->update(
                        $this->table_name,
                        [
                            'brevo_status' => 'synced',
                            'last_sync' => current_time('mysql'),
                            'error_message' => null
                        ],
                        ['id' => $contact->id]
                    );
                    $stats['fixed']++;
                    $this->log("Contact resynchronisé: {$contact->email}");
                } else {
                    // Échec de resynchronisation
                    $wpdb->update(
                        $this->table_name,
                        [
                            'brevo_status' => 'error',
                            'error_message' => 'Contact non trouvé dans Brevo après resynchronisation'
                        ],
                        ['id' => $contact->id]
                    );
                    $this->log("Échec de resynchronisation pour {$contact->email}");
                }
            }
            
            // Petit délai pour éviter de surcharger l'API Brevo
            usleep(100000); // 0.1 seconde
        }
        
        $this->log("Vérification terminée: {$stats['verified']} contacts vérifiés, {$stats['errors']} erreurs, {$stats['fixed']} corrigés");
        return $stats;
    }
}
