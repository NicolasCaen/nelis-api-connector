<?php
/**
 * Classe de synchronisation complète Nelis <-> Brevo
 */
class ContactSynchronizer {
    private $nelis_client;
    private $brevo_connector;
    private $table_name;
    private $date_filter_field;
    
    public function __construct() {
        global $wpdb;
        if ($wpdb) {
            $this->table_name = $wpdb->prefix . 'nelis_brevo_sync';
        } else {
            $this->log("Erreur: Objet wpdb non disponible dans le constructeur");
            $this->table_name = 'wp_nelis_brevo_sync'; // Valeur par défaut au cas où
        }
        
        $this->nelis_client = new Nelis_API_Client();
        $this->brevo_connector = new BrevoConnector();
        
        // Charger le champ de filtre par date
        $this->date_filter_field = get_option('nelis_brevo_date_filter_field', 'custom_80');

    }
    
    /**
     * Créer la table de synchronisation
     */
    public function create_sync_table() {
        global $wpdb;
        if (!$wpdb) {
            $this->log("Erreur: Objet wpdb non disponible");
            return false;
        }
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $this->table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            nelis_id bigint(20) NOT NULL,
            email varchar(255) NOT NULL,
            firstname varchar(255),
            lastname varchar(255),
            brevo_status varchar(50) DEFAULT 'pending',
            brevo_response text,
            last_sync datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY nelis_id (nelis_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        return true;
        
    }
    
    /**
     * Vérifie si la table de synchronisation existe
     * 
     * @return bool True si la table existe, false sinon
     */
    public function table_exists() {
        global $wpdb;
        if (!$wpdb) {
            $this->log("Erreur: Objet wpdb non disponible");
            return false;
        }
        
        return $wpdb->get_var("SHOW TABLES LIKE '$this->table_name'") === $this->table_name;
    }
    
    /**
     * Synchronisation complète (première fois)
     */
    public function full_sync() {
        global $wpdb;
        if (!$wpdb) {
            $this->log("Erreur: Objet wpdb non disponible");
            return 0;
        }
        
        $this->log("Début synchronisation complète");
        
        // Créer un répertoire temporaire pour les fichiers JSON
        $temp_dir = plugin_dir_path(dirname(__FILE__)) . 'temp';
        if (!file_exists($temp_dir)) {
            mkdir($temp_dir, 0755, true);
        }
        
        // Vérifier et ajouter les colonnes de dates si nécessaires
        $this->check_date_columns();
        
        // Vider la table de synchronisation
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
                    if ($this->should_sync_contact($contact)) {
                        $result = $this->process_nelis_contact($contact);
                        if ($result) {
                            $total_contacts++;
                        }
                    } else {
                        // Log si le contact n'est pas traité (probablement à cause du filtre de date)
                        $email = $contact['email'] ?? 'inconnu';
                        $this->log("Contact $email non traité (filtre de date)");
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
     * Vérifie si les colonnes date_creation et date_update existent et les ajoute si nécessaire
     */
    private function check_date_columns() {
        global $wpdb;
        if (!$wpdb) {
            $this->log("Erreur: Objet wpdb non disponible");
            return false;
        }
        
        $this->log("Vérification des colonnes de dates");
        
        // Vérifier si la colonne date_creation existe
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $this->table_name LIKE 'date_creation'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE $this->table_name ADD COLUMN date_creation datetime AFTER lastname");
            $this->log("Colonne date_creation ajoutée");
        }
        
        // Vérifier si la colonne date_update existe
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $this->table_name LIKE 'date_update'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE $this->table_name ADD COLUMN date_update datetime AFTER date_creation");
            $this->log("Colonne date_update ajoutée");
        }
        
        return true;
    }
    
    /**
     * Vide la table de synchronisation
     */
    private function truncate_sync_table() {
        global $wpdb;
        if (!$wpdb) {
            $this->log("Erreur: Objet wpdb non disponible");
            return false;
        }
        
        $wpdb->query("TRUNCATE TABLE $this->table_name");
        $this->log("Table de synchronisation vidée");
        return true;
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
     * Vérifie si une colonne existe dans la table
     */
    private function column_exists($column_name) {
        global $wpdb;
        if (!$wpdb) {
            $this->log("Erreur: Objet wpdb non disponible");
            return false;
        }
        
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
    private function add_column_if_not_exists($column_name, $definition, $after = '') {
        if (!$this->column_exists($column_name)) {
            global $wpdb;
            if (!$wpdb) {
                $this->log("Erreur: Objet wpdb non disponible");
                return false;
            }
            
            $after_clause = $after ? "AFTER $after" : '';
            $wpdb->query("ALTER TABLE $this->table_name ADD COLUMN $column_name $definition $after_clause");
            $this->log("Colonne $column_name ajoutée");
            return true;
        }
        return false;
    }
    
    /**
     * Vérifie si un contact doit être synchronisé en fonction de la date
     * 
     * @param array $contact Le contact Nelis à vérifier
     * @return bool True si le contact doit être synchronisé, false sinon
     */
    private function should_sync_contact($contact) {
        // Si aucun champ de filtre n'est configuré, synchroniser tous les contacts
        if (empty($this->date_filter_field)) {
            return true;
        }
        
        // Récupérer la valeur du champ date
        $custom_fields = $contact['customfieldsvalues'] ?? [];
  
        $date_value = null;
        
        // Chercher le champ personnalisé correspondant au filtre
        $field_id = str_replace('custom_', '', $this->date_filter_field);

        // Vérifie si le champ existe et n'est pas vide
        if (empty($custom_fields[$field_id])) {
            error_log("custom_fields pas exist : " . $field_id);
            return false;
        }

        $date_value = $custom_fields[$field_id];
        error_log("date récupérée : " . $date_value);


        // Si le champ date est vide, ne pas synchroniser
        if (empty($date_value)) {
            return false;
        }
        
        // Vérifier si c'est une date valide au format YYYY-MM-DD
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_value)) {
            return false;
        }
        
        // Vérifier si la date est inférieure à un an par rapport à aujourd'hui
        try {
            $date = new DateTime($date_value);
            $now = new DateTime();
            $one_year_ago = new DateTime('-1 year');
            
            // La date doit être plus récente qu'il y a un an
            return $date > $one_year_ago && $date <= $now;
        } catch (Exception $e) {
            // En cas d'erreur de date, ne pas synchroniser
            return false;
        }
    }
    
    /**
     * Traite un contact Nelis et l'insère/met à jour dans la table de synchronisation
     */
    public function process_nelis_contact($contact, $is_update = false) {
        global $wpdb;
        if (!$wpdb) {
            $this->log("Erreur: Objet wpdb non disponible");
            return false;
        }
        
        if (!isset($contact['id']) || !isset($contact['email']) || empty($contact['email'])) {
            $this->log("Contact ignoré - ID ou email manquant: " . json_encode($contact));
            return false;
        }
        
        $data_hash = $this->generate_contact_hash($contact);
        
        // S'assurer que les colonnes date_creation et date_update existent
        $this->add_column_if_not_exists('date_creation', 'DATETIME', 'lastname');
        $this->add_column_if_not_exists('date_update', 'DATETIME', 'date_creation');
        
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
     * Synchronisation incrémentielle
     */
    public function incremental_sync() {
        global $wpdb;
        if (!$wpdb) {
            $this->log("Erreur: Objet wpdb non disponible");
            return;
        }
        
        $this->log("Début synchronisation incrémentielle");
        
        $last_sync = get_option('nelis_brevo_last_sync', date('Y-m-d', strtotime('-1 day')));
        
        $total_processed = 0;
        
        // Contacts créés
        $created_contacts = $this->nelis_client->get_contacts_created_since($last_sync);
        if ($created_contacts && is_array($created_contacts)) {
            foreach ($created_contacts as $contact) {
                if ($this->should_sync_contact($contact) && $this->process_nelis_contact($contact)) {
                    $total_processed++;
                }
            }
        }
        
        // Contacts modifiés
        $updated_contacts = $this->nelis_client->get_contacts_updated_since($last_sync);
        if ($updated_contacts && is_array($updated_contacts)) {
            foreach ($updated_contacts as $contact) {
                if ($this->should_sync_contact($contact) && $this->process_nelis_contact($contact, true)) {
                    $total_processed++;
                }
            }
        }
        
        // Synchroniser vers Brevo
        $synced = $this->sync_to_brevo();
        
        // Mettre à jour la date de dernière sync
        update_option('nelis_brevo_last_sync', current_time('mysql'));
        
        $this->log("Synchronisation incrémentielle terminée: $total_processed contacts traités, $synced synchronisés vers Brevo");
        
        return $total_processed;
    }
    
    /**
     * Synchronise les contacts en attente vers Brevo
     * 
     * @return int Nombre de contacts synchronisés
     */
    public function sync_to_brevo() {
        global $wpdb;
        if (!$wpdb) {
            $this->log("Erreur: Objet wpdb non disponible");
            return 0;
        }
        
        $this->log("Début synchronisation vers Brevo");
        
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
     * Synchronise tous les contacts en attente vers Brevo sans limite
     * 
     * @return int Nombre de contacts synchronisés
     */
    public function sync_all_to_brevo() {
        global $wpdb;
        if (!$wpdb) {
            $this->log("Erreur: Objet wpdb non disponible");
            return 0;
        }
        
        $this->log("Début synchronisation complète vers Brevo (tous les contacts en attente)");
        
        // Vérifier que la table existe
        if (!$this->table_exists()) {
            $this->log("La table de synchronisation n'existe pas. Création...");
            $this->create_sync_table();
        }
        
        // Récupérer tous les contacts en attente de synchronisation sans limite
        $contacts_to_sync = $wpdb->get_results(
            "SELECT * FROM $this->table_name WHERE brevo_status = 'pending'"
        );
        
        if (empty($contacts_to_sync)) {
            $this->log("Aucun contact à synchroniser avec Brevo");
            return 0;
        }
        
        $total_contacts = count($contacts_to_sync);
        $this->log("Synchronisation de tous les contacts en attente: $total_contacts contacts à traiter");
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
                        $this->log("Contact synchronisé et vérifié dans la liste Brevo: {$contact->email} ($synced_count/$total_contacts)");
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
        
        $this->log("Synchronisation complète terminée: $synced_count contacts synchronisés sur $total_contacts");
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
     * 
     * @param string $message Le message à logger
     * @return void
     */
    private function log($message) {
        // Vérifier que les constantes de debug sont définies et activées
        if (defined('WP_DEBUG') && WP_DEBUG === true && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG === true) {
            error_log("[Nelis-Brevo Sync] " . $message);
        }
    }
    
    /**
     * Obtenir les statistiques de synchronisation
     */
    public function get_sync_stats() {
        global $wpdb;
        if (!$wpdb) {
            $this->log("Erreur: Objet wpdb non disponible");
            return;
        }
        
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
        if (!$wpdb) {
            $this->log("Erreur: Objet wpdb non disponible");
            return;
        }
        
        $updated = $wpdb->update(
            $this->table_name,
            ['brevo_status' => 'pending'],
            ['brevo_status' => 'error']
        );
        
        if ($updated === false) {
            $this->log("Erreur lors de la mise à jour des contacts en erreur");
            return false;
        }
        
        $this->log("$updated contacts remis en file d'attente");
        
        // Synchroniser vers Brevo
        $result = $this->sync_to_brevo();
        
        return $updated;
    }
    
    /**
     * Nettoyer les anciens contacts (optionnel)
     */
    public function cleanup_old_contacts($days = 30) {
        global $wpdb;
        if (!$wpdb) {
            $this->log("Erreur: Objet wpdb non disponible");
            return;
        }
        
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
        if (!$wpdb) {
            $this->log("Erreur: Objet wpdb non disponible");
            return 0;
        }
        
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
     * en comparant les emails présents dans Brevo avec ceux de la base locale
     * 
     * @return array Statistiques de vérification
     */
    public function verify_brevo_status() {
        global $wpdb;
        if (!$wpdb) {
            $this->log("Erreur: Objet wpdb non disponible");
            return ['verified' => 0, 'errors' => 0, 'fixed' => 0, 'not_in_brevo' => 0, 'in_brevo' => 0];
        }
        
        $this->log("Début vérification des statuts Brevo avec la nouvelle méthode");
        
        // Vérifier que la table existe
        if (!$this->table_exists()) {
            $this->log("La table de synchronisation n'existe pas. Création...");
            $this->create_sync_table();
            return ['verified' => 0, 'errors' => 0, 'fixed' => 0, 'not_in_brevo' => 0, 'in_brevo' => 0];
        }
        
        // Récupérer tous les emails de la liste Brevo
        $this->log("Récupération de tous les emails de Brevo");
        $brevo_emails = $this->brevo_connector->getAllContactEmails();
        $this->log(count($brevo_emails) . " emails trouvés dans Brevo");
        
        // Debug: afficher les premiers emails trouvés
        if (!empty($brevo_emails)) {
            $this->log("Premiers emails Brevo: " . implode(', ', array_slice($brevo_emails, 0, 5)));
        }
        
        if (empty($brevo_emails)) {
            $this->log("Aucun contact trouvé dans Brevo - vérifiez la configuration API");
            // Ne pas s'arrêter ici, continuer pour vérifier les contacts locaux
        }
        
        // Récupérer tous les contacts de la base locale
        $contacts = [];
        if ($wpdb) {
            $contacts = $wpdb->get_results(
                "SELECT * FROM $this->table_name"
            );
        }
        
        if (empty($contacts)) {
            $this->log("Aucun contact local à vérifier - table vide ou problème de requête");
            $this->log("Table utilisée: " . $this->table_name);
            return ['verified' => 0, 'errors' => 0, 'fixed' => 0, 'not_in_brevo' => 0, 'in_brevo' => 0];
        }
        
        $this->log("Vérification de " . count($contacts) . " contacts locaux");
        
        // Debug: afficher quelques contacts locaux
        foreach (array_slice($contacts, 0, 3) as $contact) {
            $this->log("Contact local: {$contact->email} - statut: {$contact->brevo_status}");
        }
        
        $stats = [
            'verified' => 0,   // Contacts déjà correctement marqués comme synchronisés
            'errors' => 0,    // Contacts marqués comme synchronisés mais absents de Brevo
            'fixed' => 0,      // Contacts dont le statut a été corrigé
            'not_in_brevo' => 0, // Contacts locaux absents de Brevo
            'in_brevo' => 0   // Contacts locaux présents dans Brevo
        ];
        
        // Convertir le tableau d'emails Brevo en un tableau associatif pour une recherche plus rapide
        $brevo_emails_map = array_flip($brevo_emails);
        
        foreach ($contacts as $contact) {
            $email_lower = strtolower($contact->email); // Comparaison insensible à la casse
            $is_in_brevo = isset($brevo_emails_map[$email_lower]);
            
            if ($is_in_brevo) {
                $stats['in_brevo']++;
                
                // Le contact est dans Brevo, vérifier si son statut local est correct
                if ($contact->brevo_status === 'synced') {
                    // Statut correct
                    $stats['verified']++;
                } else {
                    // Statut incorrect, mettre à jour
                    if ($wpdb->update(
                        $this->table_name,
                        [
                            'brevo_status' => 'synced',
                            'last_sync' => current_time('mysql'),
                            'error_message' => null
                        ],
                        ['id' => $contact->id]
                    )) {
                        $stats['fixed']++;
                        $this->log("Statut corrigé pour {$contact->email}: maintenant marqué comme synchronisé");
                    }
                }
            } else {
                $stats['not_in_brevo']++;
                
                // Le contact n'est pas dans Brevo, vérifier si son statut local est correct
                if ($contact->brevo_status === 'synced') {
                    // Statut incorrect, mettre à jour
                    if ($wpdb->update(
                        $this->table_name,
                        [
                            'brevo_status' => 'pending',
                            'error_message' => 'Contact marqué comme synchronisé mais absent de Brevo'
                        ],
                        ['id' => $contact->id]
                    )) {
                        $stats['errors']++;
                        $this->log("Statut corrigé pour {$contact->email}: marqué comme en attente car absent de Brevo");
                    }
                }
                // Si le contact est déjà marqué comme 'pending' ou 'error', on ne fait rien
            }
        }
        
        $this->log("Vérification terminée: {$stats['verified']} contacts déjà corrects, {$stats['fixed']} statuts corrigés, {$stats['errors']} erreurs détectées");
        $this->log("{$stats['in_brevo']} contacts locaux présents dans Brevo, {$stats['not_in_brevo']} contacts locaux absents de Brevo");
        
        return $stats;
    }
    
    /**
     * Supprime de Brevo les contacts qui ne sont pas dans la base locale
     * 
     * @return array Statistiques de suppression
     */
    public function clean_brevo_contacts() {
        global $wpdb;
        if (!$wpdb) {
            $this->log("Erreur: Objet wpdb non disponible");
            return ['total_brevo' => 0, 'total_local' => 0, 'to_delete' => 0, 'deleted' => 0];
        }
        
        $this->log("Début du nettoyage des contacts Brevo");
        
        // Vérifier que la table existe
        if (!$this->table_exists()) {
            $this->log("La table de synchronisation n'existe pas. Création...");
            $this->create_sync_table();
            return ['total_brevo' => 0, 'total_local' => 0, 'to_delete' => 0, 'deleted' => 0];
        }
        
        // Récupérer tous les emails de la liste Brevo
        $this->log("Récupération de tous les emails de Brevo");
        $brevo_emails = $this->brevo_connector->getAllContactEmails();
        $total_brevo = count($brevo_emails);
        $this->log("$total_brevo emails trouvés dans Brevo");
        
        if (empty($brevo_emails)) {
            $this->log("Aucun contact trouvé dans Brevo");
            return ['total_brevo' => 0, 'total_local' => 0, 'to_delete' => 0, 'deleted' => 0];
        }
        
        // Récupérer tous les emails de la base locale
        $local_emails = [];
        if ($wpdb) {
            $results = $wpdb->get_results("SELECT email FROM $this->table_name");
            foreach ($results as $result) {
                $local_emails[] = strtolower($result->email); // Stocker en minuscules pour comparaison insensible à la casse
            }
        }
        
        $total_local = count($local_emails);
        $this->log("$total_local emails trouvés dans la base locale");
        
        if (empty($local_emails)) {
            $this->log("Aucun contact local trouvé");
            return ['total_brevo' => $total_brevo, 'total_local' => $total_local, 'to_delete' => count($brevo_emails), 'deleted' => 0];
        }
        
        // Identifier les contacts à supprimer (présents dans Brevo mais pas dans la base locale)
        $emails_to_delete = [];
        foreach ($brevo_emails as $email) {
            if (!in_array($email, $local_emails)) {
                $emails_to_delete[] = $email;
            }
        }
        
        $to_delete_count = count($emails_to_delete);
        $this->log("$to_delete_count contacts à supprimer de Brevo");
        
        // Supprimer les contacts de Brevo
        $deleted_count = 0;
        foreach ($emails_to_delete as $email) {
            try {
                $listId = $this->brevo_connector->get_list_id();
                if ($this->brevo_connector->removeContactFromList($email, $listId)) {
                    $deleted_count++;
                    $this->log("Contact supprimé de Brevo: $email");
                } else {
                    $this->log("Erreur lors de la suppression du contact de Brevo: $email");
                }
                
                // Petit délai pour éviter de surcharger l'API Brevo
                usleep(100000); // 0.1 seconde
            } catch (Exception $e) {
                $this->log("Exception lors de la suppression du contact $email: " . $e->getMessage());
            }
        }
        
        $stats = [
            'total_brevo' => $total_brevo,
            'total_local' => $total_local,
            'to_delete' => $to_delete_count,
            'deleted' => $deleted_count
        ];
        
        $this->log("Nettoyage terminé: $deleted_count contacts supprimés de Brevo sur $to_delete_count à supprimer");
        return $stats;
    }
    
    /**
     * Supprime les contacts locaux dont la date du champ personnalisé est plus vieille qu'un an
     * 
     * @return array Statistiques de suppression
     */
    public function clean_old_local_contacts() {
        global $wpdb;
        if (!$wpdb) {
            $this->log("Erreur: Objet wpdb non disponible");
            return ['total' => 0, 'to_delete' => 0, 'deleted' => 0];
        }
        
        $this->log("Début du nettoyage des contacts locaux trop anciens");
        
        // Vérifier que la table existe
        if (!$this->table_exists()) {
            $this->log("La table de synchronisation n'existe pas. Création...");
            $this->create_sync_table();
            return ['total' => 0, 'to_delete' => 0, 'deleted' => 0];
        }
        
        // Si aucun champ de filtre n'est configuré, on ne peut pas filtrer par date
        if (empty($this->date_filter_field)) {
            $this->log("Aucun champ de filtre de date configuré. Impossible de nettoyer les contacts anciens.");
            return ['total' => 0, 'to_delete' => 0, 'deleted' => 0, 'error' => 'Aucun champ de filtre de date configuré'];
        }
        
        // Récupérer tous les contacts de la base locale
        $contacts = $wpdb->get_results("SELECT * FROM $this->table_name");
        $total = count($contacts);
        $this->log("$total contacts trouvés dans la base locale");
        
        if (empty($contacts)) {
            $this->log("Aucun contact local à vérifier");
            return ['total' => 0, 'to_delete' => 0, 'deleted' => 0];
        }
        
        // Identifier les contacts à supprimer (dont la date est plus vieille qu'un an)
        $contacts_to_delete = [];
        $one_year_ago = new DateTime('-1 year');
        $one_year_ago_str = $one_year_ago->format('Y-m-d');
        
        // Extraire l'ID du champ personnalisé utilisé pour le filtre de date
        $field_id = str_replace('custom_', '', $this->date_filter_field);
        
        foreach ($contacts as $contact) {
            // Vérifier si le champ de date existe pour ce contact
            $date_column = 'custom_' . $field_id;
            
            if (property_exists($contact, $date_column) && !empty($contact->$date_column)) {
                $date_value = $contact->$date_column;
                
                // Vérifier si c'est une date valide au format YYYY-MM-DD
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_value)) {
                    try {
                        $date = new DateTime($date_value);
                        
                        // Si la date est plus ancienne qu'il y a un an, marquer pour suppression
                        if ($date < $one_year_ago) {
                            $contacts_to_delete[] = $contact;
                        }
                    } catch (Exception $e) {
                        $this->log("Erreur lors de l'analyse de la date pour le contact {$contact->email}: " . $e->getMessage());
                    }
                } else {
                    $this->log("Format de date invalide pour le contact {$contact->email}: $date_value");
                }
            } else {
                // Si le contact n'a pas de date, on le considère comme à supprimer
                $contacts_to_delete[] = $contact;
            }
        }
        
        $to_delete_count = count($contacts_to_delete);
        $this->log("$to_delete_count contacts à supprimer de la base locale (plus vieux qu'un an)");
        
        // Supprimer les contacts de la base locale
        $deleted_count = 0;
        foreach ($contacts_to_delete as $contact) {
            // Supprimer d'abord de Brevo si le contact y est présent
            if ($contact->brevo_status === 'synced') {
                try {
                    $listId = $this->brevo_connector->get_list_id();
                    $this->brevo_connector->removeContactFromList($contact->email, $listId);
                    $this->log("Contact supprimé de Brevo avant suppression locale: {$contact->email}");
                } catch (Exception $e) {
                    $this->log("Erreur lors de la suppression du contact de Brevo: {$contact->email} - " . $e->getMessage());
                }
            }
            
            // Supprimer de la base locale
            if ($wpdb->delete($this->table_name, ['id' => $contact->id])) {
                $deleted_count++;
                $this->log("Contact supprimé de la base locale: {$contact->email}");
            } else {
                $this->log("Erreur lors de la suppression du contact de la base locale: {$contact->email}");
            }
        }
        
        $stats = [
            'total' => $total,
            'to_delete' => $to_delete_count,
            'deleted' => $deleted_count
        ];
        
        $this->log("Nettoyage terminé: $deleted_count contacts supprimés de la base locale sur $to_delete_count identifiés comme trop anciens");
        return $stats;
    }
    
    /**
     * Supprimer TOUS les contacts de Brevo
     * 
     * @return array Statistiques de suppression
     */
    public function delete_all_brevo_contacts() {
        $this->log("Début de la suppression de TOUS les contacts Brevo");
        
        // Récupérer tous les emails de la liste Brevo
        $this->log("Récupération de tous les emails de Brevo");
        $brevo_emails = $this->brevo_connector->getAllContactEmails();
        $total_brevo = count($brevo_emails);
        $this->log("$total_brevo emails trouvés dans Brevo");
        
        if (empty($brevo_emails)) {
            $this->log("Aucun contact trouvé dans Brevo");
            return ['total' => 0, 'deleted' => 0];
        }
        
        // Supprimer tous les contacts un par un
        $deleted_count = 0;
        foreach ($brevo_emails as $email) {
            $this->log("Suppression du contact: $email");
            $success = $this->brevo_connector->deleteContactByEmail($email);
            if ($success) {
                $deleted_count++;
                $this->log("Contact supprimé avec succès: $email");
            } else {
                $this->log("Erreur lors de la suppression du contact: $email");
            }
            
            // Petite pause pour éviter de surcharger l'API
            usleep(100000); // 0.1 seconde
        }
        
        $this->log("Suppression terminée: $deleted_count/$total_brevo contacts supprimés");
        
        return ['total' => $total_brevo, 'deleted' => $deleted_count];
    }
}
