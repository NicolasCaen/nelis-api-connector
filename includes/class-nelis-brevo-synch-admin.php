<?php
/**
 * Page d'administration pour la synchronisation Nelis-Brevo
 */

class NelisBrevoSyncAdmin {
    
    private $synchronizer;
    
    public function __construct() {
        $this->synchronizer = new ContactSynchronizer();
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'handle_actions']);
        add_action('wp_ajax_nelis_brevo_sync', [$this, 'ajax_sync']);
        add_action('wp_ajax_retry_failed_contacts', [$this, 'ajax_retry_failed']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'options-general.php',
            'Synchronisation Nelis-Brevo',
            'Sync Nelis-Brevo',
            'manage_options',
            'nelis-brevo-sync',
            [$this, 'admin_page']
        );
    }
    
    public function enqueue_scripts($hook) {
        if ($hook !== 'settings_page_nelis-brevo-sync') {
            return;
        }
        
        wp_enqueue_script('jquery');
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Synchronisation
            $('.sync-button').on('click', function(e) {
                e.preventDefault();
                var button = $(this);
                var syncType = button.data('sync-type');
                
                button.prop('disabled', true).text('Synchronisation en cours...');
                $('#sync-status').html('<div class="notice notice-info"><p>Synchronisation en cours...</p></div>');
                
                $.post(ajaxurl, {
                    action: 'nelis_brevo_sync',
                    sync_type: syncType,
                    _ajax_nonce: '<?php echo wp_create_nonce("nelis_brevo_sync"); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#sync-status').html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $('#sync-status').html('<div class="notice notice-error"><p>Erreur: ' + response.data + '</p></div>');
                    }
                }).fail(function() {
                    $('#sync-status').html('<div class="notice notice-error"><p>Erreur de connexion</p></div>');
                }).always(function() {
                    button.prop('disabled', false).text(button.data('original-text'));
                });
            });
            
            // Retry contacts en erreur
            $('#retry-failed').on('click', function(e) {
                e.preventDefault();
                var button = $(this);
                
                button.prop('disabled', true).text('Retry en cours...');
                
                $.post(ajaxurl, {
                    action: 'retry_failed_contacts',
                    _ajax_nonce: '<?php echo wp_create_nonce("retry_failed"); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#sync-status').html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        $('#sync-status').html('<div class="notice notice-error"><p>Erreur: ' + response.data + '</p></div>');
                    }
                }).always(function() {
                    button.prop('disabled', false).text('Relancer les échecs');
                });
            });
            
            // Stocker le texte original des boutons
            $('.sync-button').each(function() {
                $(this).data('original-text', $(this).text());
            });
        });
        </script>
        
        <style>
        .sync-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .stat-box {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            padding: 20px;
            text-align: center;
        }
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #1d2327;
            line-height: 1;
        }
        .stat-label {
            color: #646970;
            font-size: 14px;
            margin-top: 8px;
        }
        .stat-box.synced .stat-number { color: #00a32a; }
        .stat-box.pending .stat-number { color: #dba617; }
        .stat-box.error .stat-number { color: #d63638; }
        .contacts-table { margin-top: 20px; }
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-synced { background: #d1e7dd; color: #0a3622; }
        .status-pending { background: #fff3cd; color: #664d03; }
        .status-error { background: #f8d7da; color: #58151c; }
        .sync-actions { margin: 20px 0; }
        .sync-actions .button { margin-right: 10px; }
        </style>
        <?php
    }
    
    public function handle_actions() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        if (isset($_POST['clear_sync_table'])) {
            check_admin_referer('clear_sync_table');
            global $wpdb;
            $table_name = $wpdb->prefix . 'nelis_brevo_sync';
            $wpdb->query("TRUNCATE TABLE $table_name");
            add_settings_error('nelis_brevo_sync', 'table_cleared', 'Table de synchronisation vidée', 'updated');
        }
    }
    
    public function ajax_sync() {
        check_ajax_referer('nelis_brevo_sync');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Accès refusé');
        }
        
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
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function ajax_retry_failed() {
        check_ajax_referer('retry_failed');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Accès refusé');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'nelis_brevo_sync';
        
        // Remettre les contacts en erreur en statut pending
        $updated = $wpdb->update(
            $table_name,
            ['brevo_status' => 'pending'],
            ['brevo_status' => 'error']
        );
        
        // Relancer la synchronisation
        $this->synchronizer->sync_to_brevo();
        
        wp_send_json_success("$updated contacts remis en file d'attente et synchronisation relancée");
    }
    
    public function admin_page() {
        $stats = $this->synchronizer->get_sync_stats();
        $contacts = $this->get_contacts_for_display();
        $last_sync = get_option('nelis_brevo_last_sync', 'Jamais');
        
        ?>
        <div class="wrap">
            <h1>Synchronisation Nelis → Brevo</h1>
            
            <div id="sync-status"></div>
            
            <!-- Statistiques -->
            <div class="sync-stats">
                <div class="stat-box">
                    <div class="stat-number"><?php echo $stats['total']; ?></div>
                    <div class="stat-label">Total contacts</div>
                </div>
                <div class="stat-box synced">
                    <div class="stat-number"><?php echo $stats['synced']; ?></div>
                    <div class="stat-label">Synchronisés</div>
                </div>
                <div class="stat-box pending">
                    <div class="stat-number"><?php echo $stats['pending']; ?></div>
                    <div class="stat-label">En attente</div>
                </div>
                <div class="stat-box error">
                    <div class="stat-number"><?php echo $stats['errors']; ?></div>
                    <div class="stat-label">Erreurs</div>
                </div>
            </div>
            
            <div class="card">
                <h3>Actions de synchronisation</h3>
                <div class="sync-actions">
                    <button type="button" class="button button-primary sync-button" data-sync-type="incremental">
                        Synchronisation incrémentielle
                    </button>
                    <button type="button" class="button button-secondary sync-button" data-sync-type="full">
                        Synchronisation complète
                    </button>
                    
                    <?php if ($stats['errors'] > 0): ?>
                    <button type="button" class="button" id="retry-failed">
                        Relancer les échecs (<?php echo $stats['errors']; ?>)
                    </button>
                    <?php endif; ?>
                </div>
                
                <p><strong>Dernière synchronisation :</strong> <?php echo $last_sync; ?></p>
                
                <details>
                    <summary style="cursor: pointer; margin: 10px 0;"><strong>Actions avancées</strong></summary>
                    <form method="post" style="margin-top: 10px;">
                        <?php wp_nonce_field('clear_sync_table'); ?>
                        <input type="submit" name="clear_sync_table" class="button button-link-delete" 
                               value="Vider la table de synchronisation" 
                               onclick="return confirm('Êtes-vous sûr ? Cela supprimera tous les données de synchronisation.');">
                    </form>
                </details>
            </div>
            
            <!-- Tableau des contacts -->
            <div class="contacts-table">
                <h3>Contacts récents (50 derniers)</h3>
                
                <?php if (empty($contacts)): ?>
                    <p>Aucun contact trouvé. Lancez une synchronisation pour commencer.</p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>ID Nelis</th>
                                <th>Email</th>
                                <th>Nom</th>
                                <th>Prénom</th>
                                <th>Statut Brevo</th>
                                <th>Dernière sync</th>
                                <th>Créé le</th>
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
                                            <?php 
                                            switch($contact->brevo_status) {
                                                case 'synced': echo 'Synchronisé'; break;
                                                case 'pending': echo 'En attente'; break;
                                                case 'error': echo 'Erreur'; break;
                                                default: echo ucfirst($contact->brevo_status);
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php 
                                        echo $contact->last_sync ? 
                                            date('d/m/Y H:i', strtotime($contact->last_sync)) : 
                                            'Jamais'; 
                                        ?>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($contact->created_at)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <?php if ($stats['total'] > 50): ?>
                        <p><em>Affichage des 50 contacts les plus récents sur <?php echo $stats['total']; ?> total.</em></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
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
    
    private function get_contacts_for_display($limit = 50) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'nelis_brevo_sync';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d",
            $limit
        ));
    }
    
    private function display_recent_logs() {
        // Afficher les logs récents du système
        $log_file = WP_CONTENT_DIR . '/debug.log';
        
        if (file_exists($log_file)) {
            $lines = file($log_file);
            $recent_lines = array_slice($lines, -20); // 20 dernières lignes
            $sync_lines = array_filter($recent_lines, function($line) {
                return strpos($line, '[Nelis-Brevo Sync]') !== false;
            });
            
            if (!empty($sync_lines)) {
                foreach (array_reverse($sync_lines) as $line) {
                    echo esc_html($line) . "<br>";
                }
            } else {
                echo "Aucun log de synchronisation récent trouvé.";
            }
        } else {
            echo "Fichier de log non trouvé. Activez WP_DEBUG_LOG dans wp-config.php pour voir les logs.";
        }
    }
}

// Ajouter cette classe à l'initialisation
add_action('plugins_loaded', function() {
    if (is_admin()) {
        new NelisBrevoSyncAdmin();
    }
});
