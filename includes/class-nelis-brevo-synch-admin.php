<?php
/**
 * Page d'administration pour la synchronisation Nelis-Brevo
 */

class NelisBrevoSyncAdmin {
    
    private $synchronizer;
    private $hidden_fields = [];
    private $date_filter_field = '';
    
    public function __construct() {
        $this->synchronizer = new ContactSynchronizer();
        
        // S'assurer que la table de synchronisation existe
        $this->ensure_sync_table_exists();
        
        // Charger les champs cachés
        $this->hidden_fields = get_option('nelis_brevo_hidden_fields', []);
        
        // Charger le champ de filtre par date
        $this->date_filter_field = get_option('nelis_brevo_date_filter_field', 'custom_80');
        
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'handle_actions']);
        add_action('wp_ajax_nelis_brevo_sync', [$this, 'ajax_sync']);
        add_action('wp_ajax_retry_failed_contacts', [$this, 'ajax_retry_failed']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }
    
    public function add_admin_menu() {
        // Page principale de synchronisation
        add_menu_page(
            'Synchronisation Nelis-Brevo',
            'Nelis-Brevo Sync',
            'manage_options',
            'nelis-brevo-sync',
            [$this, 'admin_page'],
            'dashicons-update',
            30
        );
        
        // Sous-page Réglages
        add_submenu_page(
            'nelis-brevo-sync',
            'Réglages Nelis-Brevo',
            'Réglages',
            'manage_options',
            'nelis-brevo-settings',
            [$this, 'settings_page']
        );
    }
    
    public function enqueue_scripts($hook) {
        if ($hook !== 'toplevel_page_nelis-brevo-sync' && $hook !== 'nelis-brevo-sync_page_nelis-brevo-settings') {
            return;
        }
        
        ?>
        <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            const syncButtons = document.querySelectorAll('.sync-button');
            console.log('Boutons de synchronisation trouvés:', syncButtons.length);
            
            // Fonction pour gérer la synchronisation
            function handleSyncClick(e) {
                e.preventDefault();
                console.log('Bouton de synchronisation cliqué');
                
                const button = e.currentTarget;
                const syncType = button.getAttribute('data-sync-type');
                const originalText = button.getAttribute('data-original-text') || button.textContent;
                
                console.log('Type de synchronisation:', syncType);
                console.log('Bouton:', button.className, button.textContent);
                
                button.disabled = true;
                button.textContent = 'Synchronisation en cours...';
                
                const syncStatus = document.getElementById('sync-status');
                syncStatus.innerHTML = '<div class="notice notice-info"><p>Synchronisation en cours...</p></div>';
                
                // Vérifier que ajaxurl est défini
                if (typeof ajaxurl === 'undefined') {
                    console.error('ajaxurl n\'est pas défini');
                    syncStatus.innerHTML = '<div class="notice notice-error"><p>Erreur: ajaxurl n\'est pas défini</p></div>';
                    button.disabled = false;
                    button.textContent = originalText;
                    return;
                }
                
                // Créer les données du formulaire
                const formData = new FormData();
                formData.append('action', 'nelis_brevo_sync');
                formData.append('sync_type', syncType);
                formData.append('_ajax_nonce', '<?php echo wp_create_nonce("nelis_brevo_sync"); ?>');
                
                // Envoyer la requête AJAX
                fetch(ajaxurl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(response => {
                    console.log('Réponse reçue:', response);
                    if (response.success) {
                        syncStatus.innerHTML = '<div class="notice notice-success"><p>' + response.data + '</p></div>';
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        syncStatus.innerHTML = '<div class="notice notice-error"><p>Erreur: ' + response.data + '</p></div>';
                    }
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    syncStatus.innerHTML = '<div class="notice notice-error"><p>Erreur de connexion</p></div>';
                })
                .finally(() => {
                    button.disabled = false;
                    button.textContent = originalText;
                });
            }
            
            // Fonction pour gérer le retry des contacts en erreur
            function handleRetryClick(e) {
                e.preventDefault();
                const button = e.currentTarget;
                const originalText = button.textContent;
                
                button.disabled = true;
                button.textContent = 'Retry en cours...';
                
                const syncStatus = document.getElementById('sync-status');
                
                // Créer les données du formulaire
                const formData = new FormData();
                formData.append('action', 'retry_failed_contacts');
                formData.append('_ajax_nonce', '<?php echo wp_create_nonce("retry_failed"); ?>');
                
                // Envoyer la requête AJAX
                fetch(ajaxurl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(response => {
                    if (response.success) {
                        syncStatus.innerHTML = '<div class="notice notice-success"><p>' + response.data + '</p></div>';
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        syncStatus.innerHTML = '<div class="notice notice-error"><p>Erreur: ' + response.data + '</p></div>';
                    }
                })
                .catch(error => {
                    syncStatus.innerHTML = '<div class="notice notice-error"><p>Erreur de connexion: ' + error.message + '</p></div>';
                })
                .finally(() => {
                    button.disabled = false;
                    button.textContent = 'Relancer les échecs';
                });
            }
            
            // Attacher les gestionnaires d'événements aux boutons
            syncButtons.forEach(button => {
                button.addEventListener('click', handleSyncClick);
            });
            
            const retryButton = document.getElementById('retry-failed-button');
            if (retryButton) {
                retryButton.addEventListener('click', handleRetryClick);
            }
            
            // Fonction de recherche pour filtrer les contacts
            const searchInput = document.getElementById('contact-search');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    const table = document.getElementById('contacts-table');
                    if (!table) return;
                    
                    const rows = table.querySelectorAll('tbody tr');
                    if (!rows.length) return;
                    
                    rows.forEach(function(row) {
                        let found = false;
                        const cells = row.querySelectorAll('td');
                        
                        cells.forEach(function(cell) {
                            if (cell.textContent.toLowerCase().includes(searchTerm)) {
                                found = true;
                            }
                        });
                        
                        row.style.display = found ? '' : 'none';
                    });
                });
            }
        });
        </script>
        <?php
    }
    
    public function display_admin_page() {
        check_ajax_referer('nelis_brevo_sync');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Accès refusé');
        }
        
        // S'assurer que la table existe avant de synchroniser
        $this->ensure_sync_table_exists();
        
        $type = $_POST['sync_type'] ?? 'incremental';
        
        try {
            if ($type === 'full') {
                $result = $this->synchronizer->full_sync();
                wp_send_json_success("Synchronisation complète: $result contacts traités");
            } else {
                $this->synchronizer->incremental_sync();
                wp_send_json_success("Synchronisation incrémentielle terminée");
            }
        } catch (Exception $e) {
            $this->log("Erreur de synchronisation: " . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function ajax_retry_failed() {
        check_ajax_referer('retry_failed');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Accès refusé');
        }
        
        global $wpdb;
        if (!$wpdb) {
            $this->log("Erreur: Objet wpdb non disponible");
            wp_send_json_error("Erreur: Base de données non disponible");
            return;
        }
        
        $table_name = $wpdb->prefix . 'nelis_brevo_sync';
        
        // Remettre les contacts en erreur en statut pending
        $updated = $wpdb->update(
            $table_name,
            ['brevo_status' => 'pending'],
            ['brevo_status' => 'error']
        );
        
        if ($updated === false) {
            $this->log("Erreur lors de la mise à jour des contacts en erreur");
            wp_send_json_error("Erreur lors de la mise à jour des contacts");
            return;
        }
        
        // Relancer la synchronisation
        try {
            $this->synchronizer->sync_to_brevo();
            wp_send_json_success("$updated contacts remis en file d'attente et synchronisation relancée");
        } catch (Exception $e) {
            $this->log("Erreur lors de la relance des contacts en échec: " . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function admin_page() {
        $stats = $this->synchronizer->get_sync_stats();
        
        // Récupérer tous les contacts sans limite
        $contacts = $this->get_contacts_for_display(null);
        $last_sync = get_option('nelis_brevo_last_sync', 'Jamais');
        
        // Récupérer la structure de la table pour afficher tous les champs personnalisés
        global $wpdb;
        $table_name = '';
        $table_structure = [];
        $custom_fields = [];
        
        // Vérifier si $wpdb est disponible (il devrait l'être dans WordPress)
        if ($wpdb) {
            $table_name = $wpdb->prefix . 'nelis_brevo_sync';
            $table_structure = $wpdb->get_results("DESCRIBE $table_name");
            
            // Si la requête échoue, initialiser un tableau vide
            if (!$table_structure) {
                $table_structure = [];
            } else {
                // Extraire les champs personnalisés
                foreach ($table_structure as $column) {
                    if (strpos($column->Field, 'custom_') === 0) {
                        $custom_fields[] = $column->Field;
                    }
                }
            }
        }
        
        // Traitement du formulaire de configuration des champs cachés
        if (isset($_POST['action']) && $_POST['action'] === 'update_hidden_fields') {
            check_admin_referer('nelis_brevo_sync_action', 'nelis_brevo_sync_nonce');
            $hidden_fields = isset($_POST['hidden_fields']) ? (array) $_POST['hidden_fields'] : [];
            update_option('nelis_brevo_hidden_fields', $hidden_fields);
            $this->hidden_fields = $hidden_fields;
            add_settings_error('nelis_brevo_sync', 'fields_updated', 'Configuration des champs mise à jour', 'success');
        }
        
        // Traitement du formulaire de configuration du champ de filtre par date
        if (isset($_POST['action']) && $_POST['action'] === 'update_date_filter') {
            check_admin_referer('nelis_brevo_sync_action', 'nelis_brevo_sync_nonce');
            $date_filter_field = isset($_POST['date_filter_field']) ? sanitize_text_field($_POST['date_filter_field']) : 'custom_80';
            update_option('nelis_brevo_date_filter_field', $date_filter_field);
            $this->date_filter_field = $date_filter_field;
            add_settings_error('nelis_brevo_sync', 'date_filter_updated', 'Configuration du filtre par date mise à jour', 'success');
        }
        
        ?>
        <div class="wrap">
            <h1>Synchronisation Nelis → Brevo</h1>
            
            <div id="sync-status"></div>
            
            <!-- Statistiques -->
            <div class="sync-stats">
                <div class="stat-box">
                    <h3>Statistiques</h3>
                    <p>Total des contacts: <?php echo esc_html($stats['total'] ?? 0); ?></p>
                    <p>Contacts synchronisés: <?php echo esc_html($stats['synced'] ?? 0); ?></p>
                    <p>Contacts en attente: <?php echo esc_html($stats['pending'] ?? 0); ?></p>
                    <p>Contacts en erreur: <?php echo esc_html($stats['error'] ?? 0); ?></p>
                    <p>Dernière synchronisation: <?php echo esc_html($last_sync); ?></p>
                </div>
                
                <div class="stat-box">
                    <h3>Actions</h3>
                    <p>
                        <button class="button button-primary sync-button" data-sync-type="incremental" data-original-text="Synchronisation incrémentielle">
                            Synchronisation incrémentielle
                        </button>
                    </p>
                    <p>
                        <button class="button sync-button" data-sync-type="full" data-original-text="Synchronisation complète">
                            Synchronisation complète
                        </button>
                    </p>
                    <p>
                        <button class="button button-primary sync-button" data-sync-type="sync_all" data-original-text="Tout synchroniser vers Brevo">
                            Tout synchroniser vers Brevo
                        </button>
                    </p>
                    <p>
                        <button class="button button-secondary sync-button" data-sync-type="verify" data-original-text="Vérifier la synchronisation avec Brevo">
                            Vérifier la synchronisation avec Brevo
                        </button>
                    </p>
                    <p>
                        <button class="button button-secondary sync-button" data-sync-type="clean" data-original-text="Supprimer de Brevo les contacts absents localement">
                            Supprimer de Brevo les contacts absents localement
                        </button>
                    </p>
                    <p>
                        <button class="button button-secondary sync-button" data-sync-type="clean_old" data-original-text="Supprimer les contacts locaux trop anciens (>1 an)">
                            Supprimer les contacts locaux trop anciens (>1 an)
                        </button>
                    </p>
                    <?php if (($stats['error'] ?? 0) > 0): ?>
                    <p>
                        <button id="retry-failed-button" class="button">Relancer les échecs</button>
                    </p>
                    <?php endif; ?>
                    
                    <!-- Bouton pour vider la table -->
                    <p style="margin-top: 20px;">
                        <form method="post" action="" onsubmit="return confirm('Attention : Cette action va supprimer toutes les données de synchronisation. Continuer ?');">
                            <?php wp_nonce_field('nelis_brevo_sync_action', 'nelis_brevo_sync_nonce'); ?>
                            <input type="hidden" name="action" value="truncate_table">
                            <button type="submit" class="button button-secondary" style="color: #a00;">
                                Vider la table de synchronisation
                            </button>
                        </form>
                    </p>
                    
                </div>
            </div>
            
            <!-- Recherche -->
            <div style="margin: 20px 0;">
                <input type="text" id="contact-search" placeholder="Rechercher un contact..." style="width: 300px; padding: 8px;">
            </div>
            
            <!-- Tableau des contacts -->
            <div class="contacts-table-container" style="overflow-x: auto;">
                <table id="contacts-table" class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>ID Nelis</th>
                            <th>Email</th>
                            <th>Nom</th>
                            <th>Prénom</th>
                            <th>Statut Brevo</th>
                            <th>Date création</th>
                            <th>Date mise à jour</th>
                            <?php
                            // Afficher les en-têtes pour les champs personnalisés
                            if (!empty($table_structure)) {
                                foreach ($table_structure as $column) {
                                    if (strpos($column->Field, 'custom_') === 0 && !in_array($column->Field, $this->hidden_fields)) {
                                        echo '<th>' . esc_html(str_replace('custom_', '', $column->Field)) . '</th>';
                                    }
                                }
                            }
                            ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contacts as $contact): ?>
                            <tr>
                                <td><?php echo esc_html($contact->nelis_id); ?></td>
                                <td><?php echo esc_html($contact->email); ?></td>
                                <td><?php echo esc_html($contact->lastname); ?></td>
                                <td><?php echo esc_html($contact->firstname); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo esc_attr($contact->brevo_status); ?>">
                                        <?php echo esc_html($contact->brevo_status); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($contact->created_at); ?></td>
                                <td><?php echo esc_html($contact->updated_at); ?></td>
                                <?php
                                // Afficher les valeurs des champs personnalisés
                                if (!empty($table_structure)) {
                                    foreach ($table_structure as $column) {
                                        if (strpos($column->Field, 'custom_') === 0 && !in_array($column->Field, $this->hidden_fields)) {
                                            $field = $column->Field;
                                            echo '<td>' . (isset($contact->$field) ? esc_html($contact->$field) : '') . '</td>';
                                        }
                                    }
                                }
                                ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <style>
            .sync-stats {
                display: flex;
                gap: 20px;
                margin-bottom: 20px;
            }
            .stat-box {
                background: #fff;
                border: 1px solid #ccd0d4;
                padding: 15px;
                border-radius: 4px;
                flex: 1;
            }
            .status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: bold;
                text-transform: uppercase;
            }
            .status-synced {
                background: #d4edda;
                color: #155724;
            }
            .status-pending {
                background: #fff3cd;
                color: #856404;
            }
            .status-error {
                background: #f8d7da;
                color: #721c24;
            }
            </style>
            
            <!-- Logs récents -->
            <div class="card" style="margin-top: 20px;">
                <h3>État de la synchronisation</h3>
                <div style="background: #f1f1f1; padding: 15px; border-radius: 4px; font-family: monospace; max-height: 300px; overflow-y: auto;">
                    <?php $this->display_recent_logs(); ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Page de réglages pour la configuration des champs personnalisés
     */
    public function settings_page() {
        // Récupérer la structure de la table pour afficher tous les champs personnalisés
        global $wpdb;
        $table_name = '';
        $table_structure = [];
        $custom_fields = [];
        
        // Vérifier si $wpdb est disponible (il devrait l'être dans WordPress)
        if ($wpdb) {
            $table_name = $wpdb->prefix . 'nelis_brevo_sync';
            $table_structure = $wpdb->get_results("DESCRIBE $table_name");
            
            // Si la requête échoue, initialiser un tableau vide
            if (!$table_structure) {
                $table_structure = [];
            } else {
                // Extraire les champs personnalisés
                foreach ($table_structure as $column) {
                    if (strpos($column->Field, 'custom_') === 0) {
                        $custom_fields[] = $column->Field;
                    }
                }
            }
        }
        
        // Traitement du formulaire de configuration des champs cachés
        if (isset($_POST['action']) && $_POST['action'] === 'update_hidden_fields') {
            check_admin_referer('nelis_brevo_sync_action', 'nelis_brevo_sync_nonce');
            $hidden_fields = isset($_POST['hidden_fields']) ? (array) $_POST['hidden_fields'] : [];
            update_option('nelis_brevo_hidden_fields', $hidden_fields);
            $this->hidden_fields = $hidden_fields;
            add_settings_error('nelis_brevo_sync', 'fields_updated', 'Configuration des champs mise à jour', 'success');
        }
        
        // Traitement du formulaire de configuration du champ de filtre par date
        if (isset($_POST['action']) && $_POST['action'] === 'update_date_filter') {
            check_admin_referer('nelis_brevo_sync_action', 'nelis_brevo_sync_nonce');
            $date_filter_field = isset($_POST['date_filter_field']) ? sanitize_text_field($_POST['date_filter_field']) : 'custom_80';
            update_option('nelis_brevo_date_filter_field', $date_filter_field);
            $this->date_filter_field = $date_filter_field;
            add_settings_error('nelis_brevo_sync', 'date_filter_updated', 'Configuration du filtre par date mise à jour', 'success');
        }
        
        ?>
        <div class="wrap">
            <h1>Réglages Nelis-Brevo Sync</h1>
            
            <?php settings_errors('nelis_brevo_sync'); ?>
            
            <?php if (!empty($custom_fields)): ?>
            <!-- Configuration des champs cachés -->
            <div class="card" style="margin-bottom: 20px;">
                <h2>Configuration des champs personnalisés</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('nelis_brevo_sync_action', 'nelis_brevo_sync_nonce'); ?>
                    <input type="hidden" name="action" value="update_hidden_fields">
                    <table class="form-table">
                        <tr>
                            <th scope="row">Champs à masquer</th>
                            <td>
                                <fieldset>
                                    <legend class="screen-reader-text"><span>Champs à masquer</span></legend>
                                    <?php foreach ($custom_fields as $field): ?>
                                        <?php $field_name = str_replace('custom_', '', $field); ?>
                                        <label style="display: block; margin-bottom: 10px;">
                                            <input type="checkbox" name="hidden_fields[]" value="<?php echo esc_attr($field); ?>" 
                                                <?php checked(in_array($field, $this->hidden_fields)); ?>>
                                            <strong><?php echo esc_html($field_name); ?></strong> (<?php echo esc_html($field); ?>)
                                        </label>
                                    <?php endforeach; ?>
                                    <p class="description">Sélectionnez les champs personnalisés que vous souhaitez masquer dans le tableau de synchronisation.</p>
                                </fieldset>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Enregistrer la configuration des champs'); ?>
                </form>
            </div>
            
            <!-- Configuration du filtre par date -->
            <div class="card">
                <h2>Configuration du filtre par date</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('nelis_brevo_sync_action', 'nelis_brevo_sync_nonce'); ?>
                    <input type="hidden" name="action" value="update_date_filter">
                    <table class="form-table">
                        <tr>
                            <th scope="row">Champ de filtre par date</th>
                            <td>
                                <select name="date_filter_field" class="regular-text">
                                    <?php foreach ($custom_fields as $field): ?>
                                        <?php $field_name = str_replace('custom_', '', $field); ?>
                                        <option value="<?php echo esc_attr($field); ?>" <?php selected($field, $this->date_filter_field); ?>>
                                            <?php echo esc_html($field_name); ?> (<?php echo esc_html($field); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    Sélectionnez le champ personnalisé qui contient la date de référence pour filtrer les contacts.<br>
                                    <strong>Format attendu :</strong> YYYY-MM-DD<br>
                                    <strong>Règle :</strong> Les contacts seront synchronisés uniquement si ce champ contient une date valide et que cette date est inférieure à un an par rapport à aujourd'hui.
                                </p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Enregistrer la configuration du filtre'); ?>
                </form>
            </div>
            <?php else: ?>
            <div class="notice notice-warning">
                <p><strong>Aucun champ personnalisé détecté.</strong></p>
                <p>Effectuez d'abord une synchronisation pour que les champs personnalisés soient créés automatiquement.</p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Récupère tous les contacts pour l'affichage dans l'interface d'administration
     * 
     * @param int|null $limit Limite du nombre de contacts à récupérer (null pour tous)
     * @return array Tableau des contacts avec tous leurs champs
     */
    private function get_contacts_for_display($limit = null) {
        global $wpdb;
        if (!$wpdb) {
            $this->log("Erreur: Objet wpdb non disponible");
            return [];
        }
        
        $table_name = $wpdb->prefix . 'nelis_brevo_sync';
        
        // Récupérer tous les contacts sans limite
        if ($limit === null) {
            return $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC") ?: [];
        } else {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d",
                $limit
            )) ?: [];
        }
    }
    
    /**
     * S'assure que la table de synchronisation existe
     */
    private function ensure_sync_table_exists() {
        global $wpdb;
        if (!$wpdb) {
            $this->log("Erreur: Objet wpdb non disponible");
            return;
        }
        
        $table_name = $wpdb->prefix . 'nelis_brevo_sync';
        
        // Vérifier si la table existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if (!$table_exists) {
            // La table n'existe pas, on la crée
            $this->synchronizer->create_sync_table();
            $this->log("Table de synchronisation créée: $table_name");
        }
    }
    
    /**
     * Ajoute un message au log
     */
    private function log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log("[Nelis-Brevo Sync] " . $message);
        }
    }
    
    private function display_recent_logs() {
        // Afficher les logs récents du système
        $log_file = WP_CONTENT_DIR . '/debug.log';
        
        if (file_exists($log_file)) {
            $lines = file($log_file);
            if (!empty($lines)) {
                $recent_lines = array_slice($lines, -20); // 20 dernières lignes
                $sync_lines = array_filter($recent_lines, function($line) {
                    return is_string($line) && strpos($line, '[Nelis-Brevo Sync]') !== false;
                });
                
                if (!empty($sync_lines)) {
                    foreach (array_reverse($sync_lines) as $line) {
                        echo esc_html($line) . "<br>";
                    }
                } else {
                    echo "Aucun log de synchronisation récent trouvé.";
                }
            } else {
                echo "Fichier de log vide.";
            }
        } else {
            echo "Fichier de log non trouvé. Activez WP_DEBUG_LOG dans wp-config.php pour voir les logs.";
        }
    }
    
    /**
     * Gère les actions de l'admin (formulaires soumis, etc.)
     */
    public function handle_actions() {
        // Vérifier si nous sommes sur la page d'administration de notre plugin
        $page = $_GET['page'] ?? '';
        if ($page !== 'nelis-brevo-sync') {
            return;
        }
        
        // Vérifier si une action a été soumise
        if (isset($_POST['action']) && check_admin_referer('nelis_brevo_sync_action', 'nelis_brevo_sync_nonce')) {
            switch ($_POST['action']) {
                case 'manual_sync':
                    if (isset($_POST['sync_type']) && in_array($_POST['sync_type'], ['full', 'incremental'])) {
                        $type = $_POST['sync_type'];
                        
                        try {
                            if ($type === 'full') {
                                $result = $this->synchronizer->full_sync();
                                add_settings_error(
                                    'nelis_brevo_sync',
                                    'sync_success',
                                    "Synchronisation complète: $result contacts traités",
                                    'success'
                                );
                            } else {
                                $this->synchronizer->incremental_sync();
                                add_settings_error(
                                    'nelis_brevo_sync',
                                    'sync_success',
                                    "Synchronisation incrémentielle terminée",
                                    'success'
                                );
                            }
                        } catch (Exception $e) {
                            $this->log("Erreur de synchronisation: " . $e->getMessage());
                            add_settings_error(
                                'nelis_brevo_sync',
                                'sync_error',
                                "Erreur de synchronisation: " . $e->getMessage(),
                                'error'
                            );
                        }
                    }
                    break;
                    
                case 'retry_failed':
                    global $wpdb;
                    if (!$wpdb) {
                        $this->log("Erreur: Objet wpdb non disponible");
                        add_settings_error(
                            'nelis_brevo_sync',
                            'db_error',
                            "Erreur: Base de données non disponible",
                            'error'
                        );
                        break;
                    }
                    
                    $table_name = $wpdb->prefix . 'nelis_brevo_sync';
                    
                    // Remettre les contacts en erreur en statut pending
                    $updated = $wpdb->update(
                        $table_name,
                        ['brevo_status' => 'pending'],
                        ['brevo_status' => 'error']
                    );
                    
                    if ($updated === false) {
                        $this->log("Erreur lors de la mise à jour des contacts en erreur");
                        add_settings_error(
                            'nelis_brevo_sync',
                            'update_error',
                            "Erreur lors de la mise à jour des contacts",
                            'error'
                        );
                        break;
                    }
                    
                    // Relancer la synchronisation
                    try {
                        $this->synchronizer->sync_to_brevo();
                        add_settings_error(
                            'nelis_brevo_sync',
                            'retry_success',
                            "$updated contacts remis en file d'attente et synchronisation relancée",
                            'success'
                        );
                    } catch (Exception $e) {
                        $this->log("Erreur lors de la relance des contacts en échec: " . $e->getMessage());
                        add_settings_error(
                            'nelis_brevo_sync',
                            'retry_error',
                            "Erreur lors de la relance: " . $e->getMessage(),
                            'error'
                        );
                    }
                    break;
                    
                case 'truncate_table':
                    global $wpdb;
                    if (!$wpdb) {
                        $this->log("Erreur: Objet wpdb non disponible");
                        add_settings_error(
                            'nelis_brevo_sync',
                            'db_error',
                            "Erreur: Base de données non disponible",
                            'error'
                        );
                        break;
                    }
                    
                    $table_name = $wpdb->prefix . 'nelis_brevo_sync';
                    
                    // Vider la table
                    $result = $wpdb->query("TRUNCATE TABLE $table_name");
                    
                    if ($result === false) {
                        $this->log("Erreur lors de la suppression des données de la table");
                        add_settings_error(
                            'nelis_brevo_sync',
                            'truncate_error',
                            "Erreur lors de la suppression des données",
                            'error'
                        );
                    } else {
                        // Réinitialiser la date de dernière synchronisation
                        update_option('nelis_brevo_last_sync', 'Jamais');
                        
                        add_settings_error(
                            'nelis_brevo_sync',
                            'truncate_success',
                            "Table de synchronisation vidée avec succès",
                            'success'
                        );
                    }
                    break;
            }
        }
    }
    
    /**
     * Gère les requêtes AJAX pour la synchronisation
     */
    public function ajax_sync() {
        check_ajax_referer('nelis_brevo_sync');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Accès refusé');
        }
        
        // S'assurer que la table existe avant de synchroniser
        $this->ensure_sync_table_exists();
        
        $type = isset($_POST['sync_type']) ? sanitize_text_field($_POST['sync_type']) : 'incremental';
        
        try {
            if ($type === 'full') {
                $result = $this->synchronizer->full_sync();
                wp_send_json_success("Synchronisation complète: $result contacts traités");
            } elseif ($type === 'sync_all') {
                // Nouvelle option pour synchroniser tous les contacts en attente
                $result = $this->synchronizer->sync_all_to_brevo();
                wp_send_json_success("Synchronisation complète vers Brevo: $result contacts synchronisés");
            } elseif ($type === 'verify') {
                // Vérifier la synchronisation avec Brevo
                $stats = $this->synchronizer->verify_brevo_status();
                $message = "Vérification terminée: {$stats['in_brevo']} contacts présents dans Brevo, {$stats['not_in_brevo']} contacts absents de Brevo. ";
                $message .= "{$stats['verified']} contacts déjà corrects, {$stats['fixed']} statuts corrigés, {$stats['errors']} erreurs détectées.";
                wp_send_json_success($message);
            } elseif ($type === 'clean') {
                // Supprimer de Brevo les contacts absents localement
                $stats = $this->synchronizer->clean_brevo_contacts();
                $message = "Nettoyage terminé: {$stats['deleted']} contacts supprimés de Brevo sur {$stats['to_delete']} identifiés. ";
                $message .= "Total: {$stats['total_brevo']} contacts dans Brevo, {$stats['total_local']} contacts locaux.";
                wp_send_json_success($message);
            } elseif ($type === 'clean_old') {
                // Supprimer les contacts locaux trop anciens (>1 an)
                $stats = $this->synchronizer->clean_old_local_contacts();
                if (isset($stats['error'])) {
                    $message = "Erreur: {$stats['error']}";
                    wp_send_json_error($message);
                } else {
                    $message = "Nettoyage terminé: {$stats['deleted']} contacts supprimés de la base locale sur {$stats['to_delete']} identifiés comme trop anciens. ";
                    $message .= "Total: {$stats['total']} contacts dans la base locale.";
                    wp_send_json_success($message);
                }
            } else {
                $this->synchronizer->incremental_sync();
                wp_send_json_success("Synchronisation incrémentielle terminée");
            }
        } catch (Exception $e) {
            $this->log("Erreur de synchronisation: " . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }
    }
}

// Ajouter cette classe à l'initialisation
add_action('plugins_loaded', function() {
    if (is_admin()) {
        new NelisBrevoSyncAdmin();
    }
});
