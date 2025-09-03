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
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_nelis_brevo_sync', [$this, 'ajax_sync']);
        add_action('wp_ajax_retry_failed_contacts', [$this, 'ajax_retry_failed']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }
    
    public function add_admin_menu() {
        // Page principale du plugin
        add_menu_page(
            'Nelis-Brevo Sync',
            'Nelis-Brevo',
            'manage_options',
            'nelis-brevo-sync',
            [$this, 'synchronization_page'],
            'dashicons-update',
            30
        );
        
        // Sous-page Synchronisation (par défaut)
        add_submenu_page(
            'nelis-brevo-sync',
            'Synchronisation',
            'Synchronisation',
            'manage_options',
            'nelis-brevo-sync',
            [$this, 'synchronization_page']
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
        
        add_submenu_page(
            'nelis-brevo-sync',
            'URLs de Cron Nelis-Brevo',
            'URLs de Cron',
            'manage_options',
            'nelis-brevo-cron-urls',
            [$this, 'cron_urls_page']
        );
    }
    
    public function enqueue_scripts($hook) {
        if (!in_array($hook, ['toplevel_page_nelis-brevo-sync', 'nelis-brevo_page_nelis-brevo-settings'])) {
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
            
            // Fonction d'export CSV
            const exportCsvBtn = document.getElementById('export-csv-btn');
            if (exportCsvBtn) {
                exportCsvBtn.addEventListener('click', function() {
                    const table = document.getElementById('contacts-table');
                    if (!table) {
                        alert('Aucun tableau trouvé pour l\'export');
                        return;
                    }
                    
                    let csvContent = '';
                    
                    // Récupérer les en-têtes
                    const headers = table.querySelectorAll('thead th');
                    const headerRow = Array.from(headers).map(th => {
                        return '"' + th.textContent.trim().replace(/"/g, '""') + '"';
                    }).join(',');
                    csvContent += headerRow + '\n';
                    
                    // Récupérer les données visibles (non filtrées)
                    const rows = table.querySelectorAll('tbody tr');
                    rows.forEach(function(row) {
                        if (row.style.display !== 'none') {
                            const cells = row.querySelectorAll('td');
                            const rowData = Array.from(cells).map(td => {
                                let cellText = td.textContent.trim();
                                // Nettoyer le texte des badges de statut
                                if (td.querySelector('.status-badge')) {
                                    cellText = td.querySelector('.status-badge').textContent.trim();
                                }
                                return '"' + cellText.replace(/"/g, '""') + '"';
                            }).join(',');
                            csvContent += rowData + '\n';
                        }
                    });
                    
                    // Créer et télécharger le fichier
                    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                    const link = document.createElement('a');
                    const url = URL.createObjectURL(blob);
                    link.setAttribute('href', url);
                    
                    // Nom du fichier avec la date
                    const now = new Date();
                    const dateStr = now.getFullYear() + '-' + 
                                  String(now.getMonth() + 1).padStart(2, '0') + '-' + 
                                  String(now.getDate()).padStart(2, '0') + '_' +
                                  String(now.getHours()).padStart(2, '0') + '-' +
                                  String(now.getMinutes()).padStart(2, '0');
                    
                    link.setAttribute('download', 'contacts_nelis_brevo_' + dateStr + '.csv');
                    link.style.visibility = 'hidden';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
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
        
        
        ?>
        <div class="wrap nelis-brevo-sync">
            <div class="sync-header">
                <h1><span class="dashicons dashicons-update"></span> Synchronisation Nelis → Brevo</h1>
                <p class="sync-description">Gérez la synchronisation des contacts entre Nelis et Brevo</p>
            </div>
            
            <div id="sync-status"></div>
            
            <!-- Statistiques et Actions -->
            <div class="sync-grid">
                <div class="sync-card stats-card">
                    <div class="card-header">
                        <h2><span class="dashicons dashicons-chart-bar"></span> Statistiques</h2>
                        <p class="card-description">État actuel de la synchronisation</p>
                    </div>
                    <div class="card-content">
                        <div class="stats-grid">
                            <div class="stat-item">
                                <div class="stat-number"><?php echo esc_html($stats['total'] ?? 0); ?></div>
                                <div class="stat-label">Total contacts</div>
                            </div>
                            <div class="stat-item success">
                                <div class="stat-number"><?php echo esc_html($stats['synced'] ?? 0); ?></div>
                                <div class="stat-label">Synchronisés</div>
                            </div>
                            <div class="stat-item warning">
                                <div class="stat-number"><?php echo esc_html($stats['pending'] ?? 0); ?></div>
                                <div class="stat-label">En attente</div>
                            </div>
                            <div class="stat-item error">
                                <div class="stat-number"><?php echo esc_html($stats['error'] ?? 0); ?></div>
                                <div class="stat-label">En erreur</div>
                            </div>
                        </div>
                        <div class="last-sync">
                            <span class="dashicons dashicons-clock"></span>
                            Dernière sync: <?php echo esc_html($last_sync); ?>
                        </div>
                    </div>
                </div>
                
                <div class="sync-card actions-card">
                    <div class="card-header">
                        <h2><span class="dashicons dashicons-admin-tools"></span> Actions de synchronisation</h2>
                        <p class="card-description">Lancez différents types de synchronisation</p>
                    </div>
                    <div class="card-content">
                        <div class="action-buttons">
                            <button class="action-btn primary sync-button" data-sync-type="full" data-original-text="Synchronisation complète">
                                <span class="dashicons dashicons-download"></span>
                                Synchronisation complète
                            </button>
                            
                            <button class="action-btn secondary sync-button" data-sync-type="incremental" data-original-text="Synchronisation incrémentielle">
                                <span class="dashicons dashicons-update"></span>
                                Synchronisation incrémentielle
                            </button>
                            
                            <button class="action-btn primary sync-button" data-sync-type="sync_all" data-original-text="Tout synchroniser vers Brevo">
                                <span class="dashicons dashicons-upload"></span>
                                Tout synchroniser vers Brevo
                            </button>
                            
                            <button class="action-btn secondary sync-button" data-sync-type="verify" data-original-text="Vérifier la synchronisation avec Brevo">
                                <span class="dashicons dashicons-yes-alt"></span>
                                Vérifier avec Brevo
                            </button>
                            
                            <button class="action-btn secondary sync-button" data-sync-type="clean" data-original-text="Supprimer de Brevo les contacts absents localement">
                                <span class="dashicons dashicons-trash"></span>
                                Nettoyer Brevo
                            </button>
                            
                            <button class="action-btn danger sync-button" data-sync-type="delete_all" data-original-text="Supprimer TOUS les contacts de Brevo" onclick="return confirm('ATTENTION : Cette action va supprimer TOUS les contacts de la liste Brevo. Cette action est irréversible. Êtes-vous sûr de vouloir continuer ?');">
                                <span class="dashicons dashicons-warning"></span>
                                Supprimer TOUS les contacts
                            </button>
                            
                            <button class="action-btn secondary sync-button" data-sync-type="clean_old" data-original-text="Supprimer les contacts locaux trop anciens (>1 an)">
                                <span class="dashicons dashicons-calendar-alt"></span>
                                Supprimer anciens contacts
                            </button>
                            
                            <?php if (($stats['error'] ?? 0) > 0): ?>
                            <button id="retry-failed-button" class="action-btn warning">
                                <span class="dashicons dashicons-redo"></span>
                                Relancer les échecs
                            </button>
                            <?php endif; ?>
                            
                            <form method="post" action="" onsubmit="return confirm('Attention : Cette action va supprimer toutes les données de synchronisation. Continuer ?');" class="danger-form">
                                <?php wp_nonce_field('nelis_brevo_sync_action', 'nelis_brevo_sync_nonce'); ?>
                                <input type="hidden" name="action" value="truncate_table">
                                <button type="submit" class="action-btn danger">
                                    <span class="dashicons dashicons-database-remove"></span>
                                    Vider la table
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Recherche et Export -->
            <div class="search-export-container">
                <div class="search-field">
                    <span class="dashicons dashicons-search"></span>
                    <input type="text" id="contact-search" placeholder="Rechercher un contact par nom, email..." class="search-input">
                </div>
                <button id="export-csv-btn" class="action-btn secondary">
                    <span class="dashicons dashicons-download"></span>
                    Exporter en CSV
                </button>
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
            /* Synchronization Page Styles */
            .nelis-brevo-sync {
                max-width: 1200px;
                margin: 0;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            }
            
            .sync-header {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 2rem;
                border-radius: 12px;
                margin-bottom: 2rem;
                box-shadow: 0 4px 20px rgba(102, 126, 234, 0.3);
            }
            
            .sync-header h1 {
                margin: 0 0 0.5rem 0;
                font-size: 2rem;
                font-weight: 600;
                display: flex;
                align-items: center;
                gap: 0.75rem;
            }
            
            .sync-header .dashicons {
                font-size: 2rem;
            }
            
            .sync-description {
                margin: 0;
                font-size: 1.1rem;
                opacity: 0.9;
            }
            
            .sync-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 2rem;
                margin-bottom: 2rem;
            }
            
            @media (max-width: 768px) {
                .sync-grid {
                    grid-template-columns: 1fr;
                }
            }
            
            .sync-card {
                background: white;
                border-radius: 12px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                overflow: hidden;
                transition: transform 0.2s ease, box-shadow 0.2s ease;
            }
            
            .sync-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            }
            
            .card-header {
                padding: 1.5rem;
                border-bottom: 1px solid #e5e7eb;
            }
            
            .stats-card .card-header {
                background: linear-gradient(135deg, #10b981 0%, #059669 100%);
                color: white;
            }
            
            .actions-card .card-header {
                background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
                color: white;
            }
            
            .card-header h2 {
                margin: 0 0 0.5rem 0;
                font-size: 1.25rem;
                font-weight: 600;
                display: flex;
                align-items: center;
                gap: 0.5rem;
            }
            
            .card-description {
                margin: 0;
                opacity: 0.9;
                font-size: 0.9rem;
            }
            
            .card-content {
                padding: 1.5rem;
            }
            
            .stats-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
                margin-bottom: 1.5rem;
            }
            
            .stat-item {
                text-align: center;
                padding: 1rem;
                border-radius: 8px;
                background: #f8fafc;
                border: 2px solid #e2e8f0;
            }
            
            .stat-item.success {
                background: #f0fdf4;
                border-color: #bbf7d0;
            }
            
            .stat-item.warning {
                background: #fffbeb;
                border-color: #fde68a;
            }
            
            .stat-item.error {
                background: #fef2f2;
                border-color: #fecaca;
            }
            
            .stat-number {
                font-size: 2rem;
                font-weight: 700;
                color: #1f2937;
                margin-bottom: 0.25rem;
            }
            
            .stat-label {
                font-size: 0.875rem;
                color: #6b7280;
                font-weight: 500;
            }
            
            .last-sync {
                display: flex;
                align-items: center;
                gap: 0.5rem;
                padding: 1rem;
                background: #f8fafc;
                border-radius: 8px;
                font-size: 0.9rem;
                color: #4b5563;
            }
            
            .action-buttons {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 0.75rem;
            }
            
            .action-btn {
                display: flex;
                align-items: center;
                gap: 0.5rem;
                padding: 0.75rem 1rem;
                border: none;
                border-radius: 8px;
                font-size: 0.9rem;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.2s ease;
                text-decoration: none;
                justify-content: flex-start;
            }
            
            .action-btn:hover {
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            }
            
            .action-btn.primary {
                background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
                color: white;
            }
            
            .action-btn.secondary {
                background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
                color: white;
            }
            
            .action-btn.warning {
                background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
                color: white;
            }
            
            .action-btn.danger {
                background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
                color: white;
            }
            
            .danger-form {
                margin: 0;
            }
            
            .search-export-container {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 2rem;
                gap: 1rem;
            }
            
            @media (max-width: 768px) {
                .search-export-container {
                    flex-direction: column;
                    align-items: stretch;
                }
            }
            
            .search-field {
                position: relative;
                max-width: 400px;
                flex: 1;
            }
            
            .search-field .dashicons {
                position: absolute;
                left: 12px;
                top: 50%;
                transform: translateY(-50%);
                color: #6b7280;
                font-size: 18px;
            }
            
            .search-input {
                width: 100%;
                padding: 12px 12px 12px 40px;
                border: 2px solid #e5e7eb;
                border-radius: 8px;
                font-size: 1rem;
                transition: border-color 0.2s ease, box-shadow 0.2s ease;
            }
            
            .search-input:focus {
                outline: none;
                border-color: #3b82f6;
                box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            }
            
            .contacts-table-container {
                background: white;
                border-radius: 12px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                overflow: hidden;
                margin-bottom: 2rem;
            }
            
            #contacts-table {
                margin: 0;
                border: none;
            }
            
            #contacts-table th {
                background: #f8fafc;
                color: #374151;
                font-weight: 600;
                padding: 1rem;
                border-bottom: 2px solid #e5e7eb;
            }
            
            #contacts-table td {
                padding: 0.75rem 1rem;
                border-bottom: 1px solid #f3f4f6;
            }
            
            #contacts-table tbody tr:hover {
                background: #f8fafc;
            }
            
            .status-badge {
                display: inline-block;
                padding: 4px 8px;
                border-radius: 6px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .status-synced {
                background: #d1fae5;
                color: #065f46;
            }
            
            .status-pending {
                background: #fef3c7;
                color: #92400e;
            }
            
            .status-error {
                background: #fee2e2;
                color: #991b1b;
            }
            
            .card {
                background: white;
                border-radius: 12px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                overflow: hidden;
            }
            
            .card h3 {
                margin: 0 0 1rem 0;
                padding: 1.5rem 1.5rem 0 1.5rem;
                color: #1f2937;
                font-size: 1.25rem;
                font-weight: 600;
            }
            
            .card > div:last-child {
                padding: 0 1.5rem 1.5rem 1.5rem;
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
            } elseif ($type === 'delete_all') {
                // Supprimer TOUS les contacts de Brevo
                $stats = $this->synchronizer->delete_all_brevo_contacts();
                if (isset($stats['error'])) {
                    $message = "Erreur: {$stats['error']}";
                    wp_send_json_error($message);
                } else {
                    $message = "Suppression terminée: {$stats['deleted']} contacts supprimés de Brevo sur {$stats['total']} identifiés.";
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
    
    /**
     * Renommer la méthode admin_page en synchronization_page
     */
    public function synchronization_page() {
        $this->admin_page();
    }
    
    /**
     * Enregistrer les paramètres WordPress
     */
    public function register_settings() {
        // Utiliser les noms d'options existants pour la compatibilité
        register_setting('nelis_brevo_settings', 'nelis_brevo_hidden_fields');
        register_setting('nelis_brevo_settings', 'nelis_brevo_date_filter_field');
        register_setting('nelis_brevo_settings', 'nelis_brevo_last_sync');
        
        // Pour les API keys, utiliser le système existant de BrevoConnector
        register_setting('nelis_brevo_settings', 'brevo_config');
        register_setting('nelis_brevo_settings', 'nelis_api_client_id');
        register_setting('nelis_brevo_settings', 'nelis_api_client_secret');
        register_setting('nelis_brevo_settings', 'nelis_api_username');
        register_setting('nelis_brevo_settings', 'nelis_api_password');
    }
    
    /**
     * Page des réglages
     */
    public function settings_page() {
        ?>
        <div class="wrap nelis-brevo-settings">
            <div class="settings-header">
                <h1><span class="dashicons dashicons-admin-settings"></span> Réglages Nelis-Brevo</h1>
                <p class="settings-description">Configurez les paramètres de connexion et de synchronisation entre Nelis et Brevo</p>
            </div>
            
            <form method="post" action="options.php" class="settings-form">
                <?php
                settings_fields('nelis_brevo_settings');
                do_settings_sections('nelis_brevo_settings');
                ?>
                
                <div class="settings-grid">
                    <!-- Configuration Brevo -->
                    <div class="settings-card brevo-config">
                        <div class="card-header">
                            <h2><span class="dashicons dashicons-email-alt"></span> Configuration Brevo</h2>
                            <p class="card-description">Paramètres de connexion à l'API Brevo (ex-Sendinblue)</p>
                        </div>
                        <div class="card-content">
                            <?php $brevo_options = get_option('brevo_config', []); ?>
                            <div class="form-field">
                                <label for="brevo_api_key">
                                    <span class="field-icon dashicons dashicons-lock"></span>
                                    Clé API Brevo
                                </label>
                                <input type="password" id="brevo_api_key" name="brevo_config[brevo_api_key]" 
                                       value="<?php echo esc_attr($brevo_options['brevo_api_key'] ?? ''); ?>" 
                                       class="form-input" placeholder="xkeysib-..." />
                                <p class="field-description">Votre clé API Brevo disponible dans votre compte</p>
                            </div>
                            
                            <div class="form-field">
                                <label for="brevo_list_id">
                                    <span class="field-icon dashicons dashicons-list-view"></span>
                                    ID de la liste Brevo
                                </label>
                                <input type="number" id="brevo_list_id" name="brevo_config[brevo_list_id]" 
                                       value="<?php echo esc_attr($brevo_options['brevo_list_id'] ?? ''); ?>" 
                                       class="form-input" placeholder="123" />
                                <p class="field-description">L'ID numérique de la liste où synchroniser les contacts</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Configuration Nelis -->
                    <div class="settings-card nelis-config">
                        <div class="card-header">
                            <h2><span class="dashicons dashicons-database"></span> Configuration Nelis</h2>
                            <p class="card-description">Paramètres de connexion à l'API Nelis v4</p>
                        </div>
                        <div class="card-content">
                            <div class="form-field">
                                <label for="nelis_client_id">
                                    <span class="field-icon dashicons dashicons-admin-users"></span>
                                    Client ID
                                </label>
                                <input type="text" id="nelis_client_id" name="nelis_api_client_id" 
                                       value="<?php echo esc_attr(get_option('nelis_api_client_id')); ?>" 
                                       class="form-input" placeholder="your-client-id" />
                                <p class="field-description">Identifiant client fourni par Nelis</p>
                            </div>
                            
                            <div class="form-field">
                                <label for="nelis_client_secret">
                                    <span class="field-icon dashicons dashicons-lock"></span>
                                    Client Secret
                                </label>
                                <input type="password" id="nelis_client_secret" name="nelis_api_client_secret" 
                                       value="<?php echo esc_attr(get_option('nelis_api_client_secret')); ?>" 
                                       class="form-input" placeholder="••••••••••••••••" />
                                <p class="field-description">Clé secrète associée au client ID</p>
                            </div>
                            
                            <div class="form-field">
                                <label for="nelis_username">
                                    <span class="field-icon dashicons dashicons-admin-users"></span>
                                    Nom d'utilisateur
                                </label>
                                <input type="text" id="nelis_username" name="nelis_api_username" 
                                       value="<?php echo esc_attr(get_option('nelis_api_username')); ?>" 
                                       class="form-input" placeholder="votre-username" />
                                <p class="field-description">Nom d'utilisateur de votre compte Nelis</p>
                            </div>
                            
                            <div class="form-field">
                                <label for="nelis_password">
                                    <span class="field-icon dashicons dashicons-lock"></span>
                                    Mot de passe
                                </label>
                                <input type="password" id="nelis_password" name="nelis_api_password" 
                                       value="<?php echo esc_attr(get_option('nelis_api_password')); ?>" 
                                       class="form-input" placeholder="••••••••••••••••" />
                                <p class="field-description">Mot de passe de votre compte Nelis</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Configuration des champs personnalisés -->
                <?php
                global $wpdb;
                $table_name = $wpdb->prefix . 'nelis_brevo_sync';
                $table_structure = $wpdb->get_results("SHOW COLUMNS FROM $table_name");
                $custom_fields = [];
                
                if ($table_structure) {
                    foreach ($table_structure as $column) {
                        if (strpos($column->Field, 'custom_') === 0) {
                            $custom_fields[] = $column->Field;
                        }
                    }
                }
                ?>
                
                <?php if (!empty($custom_fields)): ?>
                <div class="settings-card custom-fields-config">
                    <div class="card-header">
                        <h2><span class="dashicons dashicons-admin-generic"></span> Configuration des champs personnalisés</h2>
                        <p class="card-description">Gérez l'affichage et le filtrage des champs personnalisés</p>
                    </div>
                    <div class="card-content">
                        <div class="form-field">
                            <label>
                                <span class="field-icon dashicons dashicons-hidden"></span>
                                Champs masqués
                            </label>
                            <div class="checkbox-grid">
                                <?php foreach ($custom_fields as $field): ?>
                                    <?php $field_name = str_replace('custom_', '', $field); ?>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="nelis_brevo_hidden_fields[]" value="<?php echo esc_attr($field); ?>" 
                                            <?php checked(in_array($field, get_option('nelis_brevo_hidden_fields', []))); ?>>
                                        <span class="checkmark"></span>
                                        <span class="checkbox-label"><?php echo esc_html($field_name); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="field-description">Sélectionnez les champs à masquer dans l'interface de synchronisation</p>
                        </div>
                        
                        <div class="form-field">
                            <label for="date_filter_field">
                                <span class="field-icon dashicons dashicons-calendar-alt"></span>
                                Champ de filtre par date
                            </label>
                            <select id="date_filter_field" name="nelis_brevo_date_filter_field" class="form-select">
                                <?php foreach ($custom_fields as $field): ?>
                                    <?php $field_name = str_replace('custom_', '', $field); ?>
                                    <option value="<?php echo esc_attr($field); ?>" <?php selected($field, get_option('nelis_brevo_date_filter_field', 'custom_80')); ?>>
                                        <?php echo esc_html($field_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="field-description">Champ utilisé pour filtrer les contacts par date (format YYYY-MM-DD, contacts de moins d'un an)</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="settings-actions">
                    <?php submit_button('Sauvegarder les réglages', 'primary large', 'submit', false, ['id' => 'save-settings']); ?>
                </div>
            </form>
        </div>
        
        <style>
        .nelis-brevo-settings {
            max-width: 1200px;
            margin: 0;
        }
        
        .settings-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(102, 126, 234, 0.3);
        }
        
        .settings-header h1 {
            margin: 0 0 10px 0;
            font-size: 28px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .settings-header .dashicons {
            font-size: 32px;
            width: 32px;
            height: 32px;
        }
        
        .settings-description {
            margin: 0;
            font-size: 16px;
            opacity: 0.9;
        }
        
        .settings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 1024px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .settings-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .settings-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.12);
        }
        
        .card-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 25px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .brevo-config .card-header {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
        }
        
        .nelis-config .card-header {
            background: linear-gradient(135deg, #f3e5f5 0%, #e1bee7 100%);
        }
        
        .custom-fields-config .card-header {
            background: linear-gradient(135deg, #e8f5e8 0%, #c8e6c9 100%);
        }
        
        .card-header h2 {
            margin: 0 0 8px 0;
            font-size: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #2c3e50;
        }
        
        .card-description {
            margin: 0;
            color: #6c757d;
            font-size: 14px;
        }
        
        .card-content {
            padding: 25px;
        }
        
        .form-field {
            margin-bottom: 25px;
        }
        
        .form-field:last-child {
            margin-bottom: 0;
        }
        
        .form-field label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .field-icon {
            color: #6c757d;
            font-size: 16px;
        }
        
        .form-input, .form-select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            background: white;
        }
        
        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .field-description {
            margin: 8px 0 0 0;
            font-size: 13px;
            color: #6c757d;
            font-style: italic;
        }
        
        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin: 12px 0;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        
        .checkbox-item:hover {
            background: #e9ecef;
        }
        
        .checkbox-item input[type="checkbox"] {
            margin: 0;
        }
        
        .checkbox-label {
            font-size: 14px;
            color: #495057;
        }
        
        .custom-fields-config {
            grid-column: 1 / -1;
        }
        
        .settings-actions {
            text-align: center;
            padding: 30px 0;
        }
        
        #save-settings {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            padding: 15px 40px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        #save-settings:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
        }
        
        .settings-form {
            background: transparent;
        }
        </style>
        <?php
    }
    
    /**
     * Page d'administration pour les URLs de cron
     */
    public function cron_urls_page() {
        // Récupérer l'instance des routes de cron
        $cron_routes = new NelisBrevosCronRoutes();
        
        // Gérer la régénération du token
        if (isset($_POST['action']) && $_POST['action'] === 'regenerate_token') {
            check_admin_referer('regenerate_cron_token');
            $cron_routes->regenerate_secret_key();
            echo '<div class="notice notice-success"><p>Token régénéré avec succès !</p></div>';
        }
        ?>
        <div class="wrap">
        <h2>URLs de Cron pour Nelis-Brevo Sync</h2>
        
        <div class="notice notice-info">
            <p><strong>Information :</strong> Ces URLs utilisent l'API REST WordPress pour une meilleure fiabilité. Format : <code>/wp-json/nelis-brevo/v1/{action}?token={token}</code></p>
        </div>

        <h3>🔑 Token de sécurité</h3>
        <div class="postbox">
            <div class="inside">
                <p><strong>Token actuel :</strong> <code id="current-token"><?php echo esc_html($cron_routes->get_secret_key()); ?></code></p>
                <form method="post" style="margin-top: 10px;">
                    <?php wp_nonce_field('regenerate_cron_token'); ?>
                    <input type="hidden" name="action" value="regenerate_token">
                    <button type="submit" class="button button-secondary" onclick="return confirm('Êtes-vous sûr de vouloir régénérer le token ? Cela invalidera toutes les URLs existantes.')">
                        🔄 Régénérer le token
                    </button>
                </form>
            </div>
        </div>

        <h3>🌐 URLs disponibles</h3>
        <div class="postbox">
            <div class="inside">
                <p><strong>URL de base :</strong> <code><?php echo esc_html($cron_routes->get_cron_base_url()); ?></code></p>
                
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>URL complète</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $actions = [
                            'sync-incremental' => 'Synchronisation incrémentale (contacts modifiés)',
                            'sync-all' => 'Synchronisation complète (tous les contacts en attente)',
                            'verify-status' => 'Vérification des statuts de synchronisation',
                            'clean-brevo' => 'Nettoyage des contacts Brevo non présents localement',
                            'clean-old' => 'Suppression des contacts locaux anciens',
                            'fetch-nelis' => 'Récupération de nouveaux contacts depuis Nelis',
                            'status' => 'Obtenir le statut de synchronisation'
                        ];
                        
                        foreach ($actions as $action => $description) {
                            $url = $cron_routes->get_cron_base_url() . $action . '?token=' . $cron_routes->get_secret_key();
                            echo '<tr>';
                            echo '<td><code>' . esc_html($action) . '</code></td>';
                            echo '<td><input type="text" value="' . esc_attr($url) . '" readonly style="width: 100%; font-family: monospace; font-size: 11px;" onclick="this.select()"></td>';
                            echo '<td>' . esc_html($description) . '</td>';
                            echo '</tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
            
            <div class="card">
                <h2>⚙️ Configuration Cron</h2>
                
                <h3>Exemple crontab Linux/Mac :</h3>
                <pre><code># Synchronisation incrémentale toutes les heures
0 * * * * curl -s "<?php echo esc_html($base_url . 'sync-incremental?token=' . $secret_key); ?>"

# Synchronisation complète tous les jours à 2h du matin
0 2 * * * curl -s "<?php echo esc_html($base_url . 'sync-all?token=' . $secret_key); ?>"

# Vérification des statuts tous les jours à 6h du matin
0 6 * * * curl -s "<?php echo esc_html($base_url . 'verify-status?token=' . $secret_key); ?>"</code></pre>
                
                <h3>Exemple avec wget :</h3>
                <pre><code>wget -q -O - "<?php echo esc_html($base_url . 'sync-incremental?token=' . $secret_key); ?>"</code></pre>
                
                <h3>Exemple avec PowerShell (Windows) :</h3>
                <pre><code>Invoke-WebRequest -Uri "<?php echo esc_html($base_url . 'sync-incremental?token=' . $secret_key); ?>" -UseBasicParsing</code></pre>
            </div>
            
            <div class="card">
                <h2>📊 Réponses JSON</h2>
                <p>Toutes les URLs retournent une réponse JSON avec les informations suivantes :</p>
                
                <h4>Succès :</h4>
                <pre><code>{
  "success": true,
  "action": "sync-incremental",
  "message": "Synchronisation incrémentale: 5 contacts synchronisés",
  "contacts_synced": 5,
  "execution_time": 2.34,
  "timestamp": "2025-01-03 14:30:00",
  "server": "votre-site.com"
}</code></pre>
                
                <h4>Erreur :</h4>
                <pre><code>{
  "success": false,
  "error": "Message d'erreur",
  "action": "sync-incremental",
  "execution_time": 0.12,
  "timestamp": "2025-01-03 14:30:00",
  "server": "votre-site.com"
}</code></pre>
            </div>
            
            <div class="card">
                <h2>🔍 Test rapide</h2>
                <p>Vous pouvez tester les URLs directement dans votre navigateur :</p>
                
                <p><a href="<?php echo esc_url($cron_routes->get_cron_base_url() . 'status?token=' . $cron_routes->get_secret_key()); ?>" target="_blank" class="button button-primary">Tester l'URL de statut</a></p>
            </div>
        </div>
        
        <style>
        .card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .card h2 {
            margin-top: 0;
        }
        pre {
            background: #f6f7f7;
            border: 1px solid #ddd;
            border-radius: 3px;
            padding: 10px;
            overflow-x: auto;
        }
        code {
            background: #f6f7f7;
            padding: 2px 4px;
            border-radius: 2px;
            font-family: Consolas, Monaco, monospace;
        }
        </style>
        
        <?php
        
        // Gérer la régénération du token
        if (isset($_POST['action']) && $_POST['action'] === 'regenerate_token') {
            if (wp_verify_nonce($_POST['_wpnonce'], 'regenerate_cron_token')) {
                $cron_routes->regenerate_secret_key();
                echo '<div class="notice notice-success"><p>Token régénéré avec succès ! Actualisez la page pour voir le nouveau token.</p></div>';
            }
        }
    }
}

// Ajouter cette classe à l'initialisation
add_action('plugins_loaded', function() {
    if (is_admin()) {
        new NelisBrevoSyncAdmin();
    }
});
