<?php

if (!defined('ABSPATH')) exit;

class NelisCronManager
{
    private $synchronizer;
    private $option_name = 'nelis_cron_config';

    public function __construct()
    {
        $this->synchronizer = new ContactSynchronizer();
        
        // Hooks pour l'administration
        if (is_admin()) {
            add_action('admin_menu', [$this, 'add_admin_menu']);
            add_action('admin_init', [$this, 'settings_init']);
        }
        
        // Enregistrer les hooks cron
        add_action('nelis_cron_sync_complete', [$this, 'run_complete_sync']);
        add_action('nelis_cron_sync_incremental', [$this, 'run_incremental_sync']);
        add_action('nelis_cron_verify_status', [$this, 'run_verify_status']);
        add_action('nelis_cron_retry_failed', [$this, 'run_retry_failed']);
        
        // Programmer les tâches cron si activées
        add_action('init', [$this, 'schedule_cron_jobs']);
        
        // Gérer les actions manuelles
        add_action('admin_init', [$this, 'handle_manual_cron_actions']);
    }

    /* ---------- Interface Admin ---------- */

    public function add_admin_menu()
    {
        add_submenu_page(
            'nelis-brevo-sync',
            'Configuration Cron',
            'Tâches Cron',
            'manage_options',
            'nelis-cron-config',
            [$this, 'admin_page']
        );
    }

    public function settings_init()
    {
        register_setting('nelis_cron_group', $this->option_name);

        add_settings_section(
            'nelis_cron_section',
            'Configuration des tâches automatiques',
            [$this, 'section_callback'],
            'nelis-cron-config'
        );

        /* ========== 1. SYNCHRONISATION COMPLÈTE ========== */
        add_settings_field(
            'enable_complete_sync',
            '<strong>1. Synchronisation complète vers Brevo</strong>',
            [$this, 'task_header_callback'],
            'nelis-cron-config',
            'nelis_cron_section',
            ['description' => 'Synchronise TOUS les contacts en attente de la base locale vers Brevo. Traite jusqu\'à 100 contacts par exécution. Recommandé : 1-2 fois par jour.']
        );

        add_settings_field(
            'enable_complete_sync_checkbox',
            'Activer la synchronisation complète',
            [$this, 'checkbox_callback'],
            'nelis-cron-config',
            'nelis_cron_section',
            ['field_id' => 'enable_complete_sync']
        );

        add_settings_field(
            'complete_sync_schedule',
            'Programmation',
            [$this, 'schedule_callback'],
            'nelis-cron-config',
            'nelis_cron_section',
            ['field_id' => 'complete_sync', 'task_name' => 'Synchronisation complète']
        );

        /* ========== 2. SYNCHRONISATION INCRÉMENTIELLE ========== */
        add_settings_field(
            'enable_incremental_sync',
            '<strong>2. Synchronisation incrémentielle depuis Nelis</strong>',
            [$this, 'task_header_callback'],
            'nelis-cron-config',
            'nelis_cron_section',
            ['description' => 'Récupère les NOUVEAUX contacts depuis l\'API Nelis et les ajoute à la base locale. Traite par lots de 100. Recommandé : toutes les heures ou 2 fois par jour.']
        );

        add_settings_field(
            'enable_incremental_sync_checkbox',
            'Activer la synchronisation incrémentielle',
            [$this, 'checkbox_callback'],
            'nelis-cron-config',
            'nelis_cron_section',
            ['field_id' => 'enable_incremental_sync']
        );

        add_settings_field(
            'incremental_sync_schedule',
            'Programmation',
            [$this, 'schedule_callback'],
            'nelis-cron-config',
            'nelis_cron_section',
            ['field_id' => 'incremental_sync', 'task_name' => 'Synchronisation incrémentielle']
        );

        /* ========== 3. VÉRIFICATION DES STATUTS ========== */
        add_settings_field(
            'enable_status_verification',
            '<strong>3. Vérification des statuts Brevo</strong>',
            [$this, 'task_header_callback'],
            'nelis-cron-config',
            'nelis_cron_section',
            ['description' => 'Vérifie que les contacts marqués comme "synchronisés" sont effectivement présents dans Brevo. Corrige les incohérences. Recommandé : 1 fois par jour.']
        );

        add_settings_field(
            'enable_status_verification_checkbox',
            'Activer la vérification des statuts',
            [$this, 'checkbox_callback'],
            'nelis-cron-config',
            'nelis_cron_section',
            ['field_id' => 'enable_status_verification']
        );

        add_settings_field(
            'status_verification_schedule',
            'Programmation',
            [$this, 'schedule_callback'],
            'nelis-cron-config',
            'nelis_cron_section',
            ['field_id' => 'status_verification', 'task_name' => 'Vérification des statuts']
        );

        /* ========== 4. RELANCE DES ÉCHECS ========== */
        add_settings_field(
            'enable_retry_failed',
            '<strong>4. Relance des contacts en erreur</strong>',
            [$this, 'task_header_callback'],
            'nelis-cron-config',
            'nelis_cron_section',
            ['description' => 'Relance automatiquement les contacts qui ont échoué lors de la synchronisation vers Brevo. Recommandé : 1-2 fois par jour.']
        );

        add_settings_field(
            'enable_retry_failed_checkbox',
            'Activer la relance des échecs',
            [$this, 'checkbox_callback'],
            'nelis-cron-config',
            'nelis_cron_section',
            ['field_id' => 'enable_retry_failed']
        );

        add_settings_field(
            'retry_failed_schedule',
            'Programmation',
            [$this, 'schedule_callback'],
            'nelis-cron-config',
            'nelis_cron_section',
            ['field_id' => 'retry_failed', 'task_name' => 'Relance des échecs']
        );
    }

    public function section_callback()
    {
        echo '<p>Configurez les tâches automatiques de synchronisation. Les tâches s\'exécuteront en arrière-plan selon la fréquence définie.</p>';
    }

    public function task_header_callback($args)
    {
        echo "<div style='background: #f9f9f9; padding: 15px; border-left: 4px solid #0073aa; margin: 10px 0;'>";
        echo "<p style='margin: 0; font-style: italic; color: #666;'>{$args['description']}</p>";
        echo "</div>";
    }

    public function checkbox_callback($args)
    {
        $options = get_option($this->option_name, []);
        $field_id = $args['field_id'];
        $checked = isset($options[$field_id]) && $options[$field_id] ? 'checked' : '';
        
        echo "<input type='checkbox' name='{$this->option_name}[{$field_id}]' value='1' {$checked} />";
        
        if (isset($args['description'])) {
            echo "<p class='description'>{$args['description']}</p>";
        }
    }

    public function schedule_callback($args)
    {
        $options = get_option($this->option_name, []);
        $field_id = $args['field_id'];
        $task_name = $args['task_name'];
        
        // Type de fréquence
        $frequency_type = isset($options[$field_id . '_frequency_type']) ? $options[$field_id . '_frequency_type'] : 'interval';
        
        echo "<div style='border: 1px solid #ddd; padding: 15px; background: #fafafa;'>";
        
        // Sélecteur de type de fréquence
        echo "<h4>Type de programmation :</h4>";
        echo "<label><input type='radio' name='{$this->option_name}[{$field_id}_frequency_type]' value='interval' " . checked($frequency_type, 'interval', false) . "> Intervalle régulier</label><br>";
        echo "<label><input type='radio' name='{$this->option_name}[{$field_id}_frequency_type]' value='specific' " . checked($frequency_type, 'specific', false) . "> Heure spécifique</label><br><br>";
        
        // Options pour intervalle régulier
        echo "<div id='{$field_id}_interval_options' style='" . ($frequency_type == 'interval' ? '' : 'display:none;') . "'>";
        echo "<h4>Fréquence :</h4>";
        $interval = isset($options[$field_id . '_interval']) ? $options[$field_id . '_interval'] : 'daily';
        $intervals = [
            'every_5_minutes' => 'Toutes les 5 minutes',
            'every_15_minutes' => 'Toutes les 15 minutes', 
            'every_30_minutes' => 'Toutes les 30 minutes',
            'hourly' => 'Toutes les heures',
            'every_2_hours' => 'Toutes les 2 heures',
            'every_4_hours' => 'Toutes les 4 heures',
            'every_6_hours' => 'Toutes les 6 heures',
            'twicedaily' => 'Deux fois par jour (12h d\'intervalle)',
            'daily' => 'Une fois par jour',
            'weekly' => 'Une fois par semaine'
        ];
        
        echo "<select name='{$this->option_name}[{$field_id}_interval]'>";
        foreach ($intervals as $value => $label) {
            $selected = selected($interval, $value, false);
            echo "<option value='{$value}' {$selected}>{$label}</option>";
        }
        echo "</select><br><br>";
        
        // Nombre d'itérations pour intervalle
        $iterations = isset($options[$field_id . '_iterations']) ? $options[$field_id . '_iterations'] : 0;
        echo "<label>Nombre d'exécutions (0 = illimité) : ";
        echo "<input type='number' name='{$this->option_name}[{$field_id}_iterations]' value='{$iterations}' min='0' style='width: 80px;'></label>";
        echo "</div>";
        
        // Options pour heure spécifique
        echo "<div id='{$field_id}_specific_options' style='" . ($frequency_type == 'specific' ? '' : 'display:none;') . "'>";
        echo "<h4>Programmation spécifique :</h4>";
        
        // Jours de la semaine
        $days = isset($options[$field_id . '_days']) ? $options[$field_id . '_days'] : [];
        if (!is_array($days)) $days = [];
        
        echo "<p><strong>Jours :</strong></p>";
        $weekdays = [
            'monday' => 'Lundi',
            'tuesday' => 'Mardi', 
            'wednesday' => 'Mercredi',
            'thursday' => 'Jeudi',
            'friday' => 'Vendredi',
            'saturday' => 'Samedi',
            'sunday' => 'Dimanche'
        ];
        
        foreach ($weekdays as $day => $label) {
            $checked = in_array($day, $days) ? 'checked' : '';
            echo "<label><input type='checkbox' name='{$this->option_name}[{$field_id}_days][]' value='{$day}' {$checked}> {$label}</label> ";
        }
        
        // Heure d'exécution
        $hour = isset($options[$field_id . '_hour']) ? $options[$field_id . '_hour'] : '02';
        $minute = isset($options[$field_id . '_minute']) ? $options[$field_id . '_minute'] : '00';
        
        echo "<p><strong>Heure d'exécution :</strong></p>";
        echo "<select name='{$this->option_name}[{$field_id}_hour]'>";
        for ($h = 0; $h < 24; $h++) {
            $h_formatted = sprintf('%02d', $h);
            $selected = selected($hour, $h_formatted, false);
            echo "<option value='{$h_formatted}' {$selected}>{$h_formatted}h</option>";
        }
        echo "</select> : ";
        
        echo "<select name='{$this->option_name}[{$field_id}_minute]'>";
        for ($m = 0; $m < 60; $m += 5) {
            $m_formatted = sprintf('%02d', $m);
            $selected = selected($minute, $m_formatted, false);
            echo "<option value='{$m_formatted}' {$selected}>{$m_formatted}</option>";
        }
        echo "</select>";
        
        // Nombre d'exécutions pour heure spécifique
        $specific_iterations = isset($options[$field_id . '_specific_iterations']) ? $options[$field_id . '_specific_iterations'] : 0;
        echo "<p><label>Nombre d'exécutions (0 = illimité) : ";
        echo "<input type='number' name='{$this->option_name}[{$field_id}_specific_iterations]' value='{$specific_iterations}' min='0' style='width: 80px;'></label></p>";
        
        echo "</div>";
        echo "</div>";
        
        // JavaScript pour basculer entre les options
        echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            const radios = document.querySelectorAll('input[name=\"{$this->option_name}[{$field_id}_frequency_type]\"');
            radios.forEach(radio => {
                radio.addEventListener('change', function() {
                    const intervalDiv = document.getElementById('{$field_id}_interval_options');
                    const specificDiv = document.getElementById('{$field_id}_specific_options');
                    
                    if (this.value === 'interval') {
                        intervalDiv.style.display = 'block';
                        specificDiv.style.display = 'none';
                    } else {
                        intervalDiv.style.display = 'none';
                        specificDiv.style.display = 'block';
                    }
                });
            });
        });
        </script>";
    }

    public function admin_page()
    {
        $next_runs = $this->get_next_cron_runs();
        ?>
        <div class="wrap">
            <h1>Configuration des Tâches Cron</h1>
            
            <?php if (!empty($next_runs)): ?>
            <div class="notice notice-info">
                <h3>Prochaines exécutions programmées :</h3>
                <ul>
                    <?php foreach ($next_runs as $hook => $time): ?>
                        <li><strong><?php echo esc_html($this->get_cron_label($hook)); ?></strong> : <?php echo esc_html(date('d/m/Y H:i:s', $time)); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('nelis_cron_group');
                do_settings_sections('nelis-cron-config');
                submit_button('Sauvegarder et programmer les tâches');
                ?>
            </form>
            
            <div class="card">
                <h2>Actions manuelles</h2>
                <p>Vous pouvez également déclencher les tâches manuellement :</p>
                <p>
                    <a href="<?php echo admin_url('admin.php?page=nelis-brevo-sync&action=manual_cron_incremental'); ?>" class="button">Lancer sync incrémentielle</a>
                    <a href="<?php echo admin_url('admin.php?page=nelis-brevo-sync&action=manual_cron_brevo'); ?>" class="button">Lancer sync Brevo</a>
                    <a href="<?php echo admin_url('admin.php?page=nelis-brevo-sync&action=manual_cron_verify'); ?>" class="button">Lancer vérification</a>
                    <a href="<?php echo admin_url('admin.php?page=nelis-brevo-sync&action=manual_cron_retry'); ?>" class="button">Relancer échecs</a>
                </p>
            </div>
        </div>
        <?php
    }

    /* ---------- Gestion des tâches cron ---------- */

    public function schedule_cron_jobs()
    {
        $options = get_option($this->option_name, []);
        
        // Nettoyer les anciennes tâches
        $this->clear_cron_jobs();
        
        // Programmer la synchronisation incrémentielle
        if (!empty($options['enable_incremental_sync'])) {
            $interval = $options['incremental_sync_interval'] ?? 'daily';
            if (!wp_next_scheduled('nelis_cron_sync_incremental')) {
                wp_schedule_event(time(), $interval, 'nelis_cron_sync_incremental');
            }
        }
        
        // Programmer la synchronisation vers Brevo
        if (!empty($options['enable_brevo_sync'])) {
            $interval = $options['brevo_sync_interval'] ?? 'daily';
            if (!wp_next_scheduled('nelis_cron_sync_all')) {
                wp_schedule_event(time(), $interval, 'nelis_cron_sync_all');
            }
        }
        
        // Programmer la vérification des statuts
        if (!empty($options['enable_status_verification'])) {
            $interval = $options['status_verification_interval'] ?? 'daily';
            if (!wp_next_scheduled('nelis_cron_verify_status')) {
                wp_schedule_event(time(), $interval, 'nelis_cron_verify_status');
            }
        }
        
        // Programmer la relance des échecs
        if (!empty($options['enable_retry_failed'])) {
            $interval = $options['retry_failed_interval'] ?? 'daily';
            if (!wp_next_scheduled('nelis_cron_retry_failed')) {
                wp_schedule_event(time(), $interval, 'nelis_cron_retry_failed');
            }
        }
    }

    public function clear_cron_jobs()
    {
        wp_clear_scheduled_hook('nelis_cron_sync_complete');
        wp_clear_scheduled_hook('nelis_cron_sync_incremental');
        wp_clear_scheduled_hook('nelis_cron_verify_status');
        wp_clear_scheduled_hook('nelis_cron_retry_failed');
    }

    /* ---------- Exécution des tâches ---------- */

    public function run_incremental_sync()
    {
        error_log("[Nelis Cron] Début synchronisation incrémentielle automatique");
        try {
            $result = $this->synchronizer->incremental_sync();
            error_log("[Nelis Cron] Synchronisation incrémentielle terminée: $result nouveaux contacts");
        } catch (Exception $e) {
            error_log("[Nelis Cron] Erreur synchronisation incrémentielle: " . $e->getMessage());
        }
    }

    public function run_complete_sync()
    {
        error_log("[Nelis Cron] Début synchronisation complète vers Brevo automatique");
        try {
            $result = $this->synchronizer->sync_to_brevo();
            error_log("[Nelis Cron] Synchronisation complète terminée: $result contacts synchronisés");
        } catch (Exception $e) {
            error_log("[Nelis Cron] Erreur synchronisation complète: " . $e->getMessage());
        }
    }

    public function run_verify_status()
    {
        error_log("[Nelis Cron] Début vérification des statuts Brevo automatique");
        try {
            $result = $this->synchronizer->verify_brevo_status();
            error_log("[Nelis Cron] Vérification terminée: " . $result['verified'] . " contacts vérifiés, " . $result['fixed'] . " statuts corrigés");
        } catch (Exception $e) {
            error_log("[Nelis Cron] Erreur vérification statuts: " . $e->getMessage());
        }
    }

    public function run_retry_failed()
    {
        error_log("[Nelis Cron] Début relance des échecs automatique");
        try {
            $result = $this->synchronizer->retry_failed_contacts();
            error_log("[Nelis Cron] Relance terminée: $result contacts relancés");
        } catch (Exception $e) {
            error_log("[Nelis Cron] Erreur relance échecs: " . $e->getMessage());
        }
    }

    /* ---------- Utilitaires ---------- */

    private function get_next_cron_runs()
    {
        $cron_jobs = [
            'nelis_cron_sync_complete' => 'Synchronisation complète vers Brevo',
            'nelis_cron_sync_incremental' => 'Synchronisation incrémentielle depuis Nelis',
            'nelis_cron_verify_status' => 'Vérification des statuts Brevo',
            'nelis_cron_retry_failed' => 'Relance des contacts en erreur'
        ];
        
        $next_runs = [];
        foreach ($cron_jobs as $hook => $label) {
            $next_run = wp_next_scheduled($hook);
            if ($next_run) {
                $next_runs[$hook] = $next_run;
            }
        }
        
        return $next_runs;
    }

    private function get_cron_label($hook)
    {
        $labels = [
            'nelis_cron_sync_complete' => 'Synchronisation complète vers Brevo',
            'nelis_cron_sync_incremental' => 'Synchronisation incrémentielle depuis Nelis',
            'nelis_cron_verify_status' => 'Vérification des statuts Brevo',
            'nelis_cron_retry_failed' => 'Relance des contacts en erreur'
        ];
        
        return $labels[$hook] ?? $hook;
    }

    /* ---------- Actions manuelles ---------- */

    public function handle_manual_cron_actions()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $action = $_GET['action'] ?? '';
        
        switch ($action) {
            case 'manual_cron_complete':
                $this->run_complete_sync();
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success is-dismissible"><p>Synchronisation complète vers Brevo lancée manuellement.</p></div>';
                });
                break;
                
            case 'manual_cron_incremental':
                $this->run_incremental_sync();
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success"><p>Synchronisation incrémentielle lancée manuellement.</p></div>';
                });
                break;
                
            case 'manual_cron_brevo':
                $this->run_complete_sync();
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success"><p>Synchronisation vers Brevo lancée manuellement.</p></div>';
                });
                break;
                
            case 'manual_cron_verify':
                $this->run_verify_status();
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success"><p>Vérification des statuts lancée manuellement.</p></div>';
                });
                break;
                
            case 'manual_cron_retry':
                $this->run_retry_failed();
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success"><p>Relance des échecs lancée manuellement.</p></div>';
                });
                break;
        }
    }
}
