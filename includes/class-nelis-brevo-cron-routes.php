<?php
/**
 * Classe pour gérer les routes de cron pour la synchronisation Nelis-Brevo
 * Utilise l'API REST WordPress pour des URLs plus fiables
 */

class NelisBrevosCronRoutes {
    
    private $synchronizer;
    private $secret_key;
    private $namespace = 'nelis-brevo/v1';
    
    public function __construct() {
        $this->synchronizer = new ContactSynchronizer();
        
        // Récupérer le token existant ou utiliser le token par défaut
        $this->secret_key = get_option('nelis_brevo_cron_secret', 'UUtVo8JPk2LPPJuX4hQsRtZqS8fZVkem');
        
        // Sauvegarder le token seulement s'il n'existe pas
        if (!get_option('nelis_brevo_cron_secret')) {
            update_option('nelis_brevo_cron_secret', $this->secret_key);
        }
        
        // Enregistrer les routes REST API
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }
    
    /**
     * Générer une clé secrète pour sécuriser les routes
     */
    private function generate_secret_key(): string {
        // Utiliser une méthode compatible si wp_generate_password n'est pas disponible
        if (function_exists('wp_generate_password')) {
            $key = wp_generate_password(32, false);
        } else {
            // Fallback pour générer une clé sécurisée
            $key = bin2hex(random_bytes(16)); // 32 caractères hexadécimaux
        }
        update_option('nelis_brevo_cron_secret', $key);
        return $key;
    }
    
    /**
     * Enregistrer les routes REST API
     */
    public function register_rest_routes() {
        // Route pour toutes les actions de cron
        register_rest_route($this->namespace, '/(?P<action>[a-zA-Z0-9-]+)', [
            'methods' => ['GET', 'POST'],
            'callback' => [$this, 'handle_rest_request'],
            'permission_callback' => '__return_true', // Pas de restriction WordPress, on gère l'auth manuellement
            'args' => [
                'action' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_string($param) && !empty($param);
                    }
                ],
                'token' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_string($param) && !empty($param);
                    }
                ]
            ]
        ]);
    }
    
    /**
     * Gérer les requêtes REST
     */
    public function handle_rest_request($request) {
        $action = $request->get_param('action');
        $provided_token = $request->get_param('token');
        
        // Vérifier l'authentification manuellement
        if (!$provided_token) {
            return new WP_Error('missing_token', 'Token manquant', ['status' => 401]);
        }
        
        
        if (!hash_equals($this->secret_key, $provided_token)) {
            return new WP_Error('invalid_token', 'Token invalide - fourni: ' . substr($provided_token, 0, 8) . '... attendu: ' . substr($this->secret_key, 0, 8) . '...', ['status' => 403]);
        }
        
        // Exécuter l'action demandée
        return $this->execute_cron_action($action);
    }
    
    /**
     * Vérifier l'authentification REST
     */
    public function verify_rest_auth($request) {
        $provided_token = $request->get_param('token');
        if (!$provided_token) {
            return new WP_Error('missing_token', 'Token manquant', ['status' => 401]);
        }
        
        if (!hash_equals($this->secret_key, $provided_token)) {
            return new WP_Error('invalid_token', 'Token invalide', ['status' => 403]);
        }
        
        return true;
    }
    
    /**
     * Exécuter une action de cron
     */
    private function execute_cron_action(string $action) {
        $start_time = microtime(true);
        
        try {
            switch ($action) {
                case 'sync-incremental':
                    $result = $this->synchronizer->sync_to_brevo();
                    return [
                        'success' => true,
                        'action' => 'sync-incremental',
                        'message' => "Synchronisation incrémentale: $result contacts synchronisés",
                        'contacts_synced' => $result,
                        'execution_time' => round(microtime(true) - $start_time, 2)
                    ];
                    
                case 'sync-all':
                    $result = $this->synchronizer->sync_all_to_brevo();
                    return [
                        'success' => true,
                        'action' => 'sync-all',
                        'message' => "Synchronisation complète: $result contacts synchronisés",
                        'contacts_synced' => $result,
                        'execution_time' => round(microtime(true) - $start_time, 2)
                    ];
                    
                case 'verify-status':
                    $stats = $this->synchronizer->verify_brevo_status();
                    return [
                        'success' => true,
                        'action' => 'verify-status',
                        'message' => "Vérification terminée: {$stats['in_brevo']} contacts présents dans Brevo, {$stats['not_in_brevo']} contacts absents",
                        'stats' => $stats,
                        'execution_time' => round(microtime(true) - $start_time, 2)
                    ];
                    
                case 'clean-brevo':
                    $result = $this->synchronizer->clean_brevo_contacts();
                    return [
                        'success' => true,
                        'action' => 'clean-brevo',
                        'message' => "Nettoyage Brevo: $result contacts supprimés",
                        'contacts_cleaned' => $result,
                        'execution_time' => round(microtime(true) - $start_time, 2)
                    ];
                    
                case 'clean-old':
                    // Utiliser clean_brevo_contacts comme alternative
                    $result = $this->synchronizer->clean_brevo_contacts();
                    return [
                        'success' => true,
                        'action' => 'clean-old',
                        'message' => "Nettoyage anciens contacts: $result contacts supprimés",
                        'contacts_cleaned' => $result,
                        'execution_time' => round(microtime(true) - $start_time, 2)
                    ];
                    
                case 'fetch-nelis':
                    // Utiliser sync_to_brevo comme alternative pour récupérer et synchroniser
                    $result = $this->synchronizer->sync_to_brevo();
                    return [
                        'success' => true,
                        'action' => 'fetch-nelis',
                        'message' => "Récupération et synchronisation Nelis: $result contacts traités",
                        'contacts_processed' => $result,
                        'execution_time' => round(microtime(true) - $start_time, 2)
                    ];
                    
                case 'status':
                    return $this->get_sync_status();
                    
                default:
                    return new WP_Error('invalid_action', 'Action non reconnue: ' . $action, ['status' => 400]);
            }
            
        } catch (Exception $e) {
            return new WP_Error('execution_error', $e->getMessage(), ['status' => 500]);
        }
    }
    
    /**
     * Obtenir le statut de synchronisation
     */
    private function get_sync_status() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'nelis_brevo_sync';
        
        // Statistiques de base
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN brevo_status = 'synced' THEN 1 ELSE 0 END) as synced,
                SUM(CASE WHEN brevo_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN brevo_status = 'error' THEN 1 ELSE 0 END) as errors,
                MAX(last_sync) as last_sync_date
            FROM $table_name
        ");
        
        return [
            'success' => true,
            'action' => 'status',
            'stats' => [
                'total_contacts' => (int) $stats->total,
                'synced_contacts' => (int) $stats->synced,
                'pending_contacts' => (int) $stats->pending,
                'error_contacts' => (int) $stats->errors,
                'last_sync_date' => $stats->last_sync_date,
                'sync_percentage' => $stats->total > 0 ? round(($stats->synced / $stats->total) * 100, 1) : 0
            ],
            'cron_secret_key' => $this->secret_key,
            'available_actions' => $this->get_available_actions(),
            'timestamp' => current_time('mysql'),
            'server' => $_SERVER['SERVER_NAME'] ?? 'unknown'
        ];
    }
    
    /**
     * Obtenir la liste des actions disponibles
     */
    private function get_available_actions(): array {
        return [
            'sync-incremental' => 'Synchronisation incrémentale (contacts modifiés)',
            'sync-all' => 'Synchronisation complète (tous les contacts en attente)',
            'verify-status' => 'Vérification des statuts de synchronisation',
            'clean-brevo' => 'Nettoyage des contacts Brevo non présents localement',
            'clean-old' => 'Suppression des contacts locaux anciens',
            'fetch-nelis' => 'Récupération de nouveaux contacts depuis Nelis',
            'status' => 'Obtenir le statut de synchronisation'
        ];
    }
    
    
    /**
     * Obtenir l'URL de base pour les crons
     */
    public function get_cron_base_url(): string {
        return rest_url($this->namespace . '/');
    }
    
    /**
     * Obtenir le token secret
     */
    public function get_secret_key(): string {
        return $this->secret_key;
    }
    
    /**
     * Régénérer le token secret
     */
    public function regenerate_secret_key(): string {
        return $this->generate_secret_key();
    }
}

// Initialiser la classe
new NelisBrevosCronRoutes();
