<?php


if (!defined('ABSPATH')) exit;

class BrevoConnector
{
    private string $option_name = 'brevo_config';
    private string $baseUrl = 'https://api.brevo.com/v3';

    public function __construct()
    {
        if (is_admin()) {
            add_action('admin_menu', [$this, 'add_admin_menu']);
            add_action('admin_init', [$this, 'settings_init']);
        }
    }

    /* ---------- Partie ADMIN ---------- */

    public function add_admin_menu()
    {
        // Ne plus créer de page dans le menu Réglages
        // La configuration Brevo sera accessible via le menu Nelis-Brevo Sync
    }

    public function settings_init()
    {
        register_setting('brevo_config_group', $this->option_name);

        add_settings_section(
            'brevo_config_section',
            'Configuration Brevo',
            null,
            'brevo-config'
        );

        add_settings_field(
            'brevo_api_key',
            'Clé API Brevo',
            [$this, 'field_callback'],
            'brevo-config',
            'brevo_config_section',
            ['field_id' => 'brevo_api_key', 'type' => 'password']
        );

        add_settings_field(
            'brevo_list_id',
            'ID de la liste Brevo',
            [$this, 'field_callback'],
            'brevo-config',
            'brevo_config_section',
            ['field_id' => 'brevo_list_id', 'type' => 'number']
        );
    }

    public function field_callback($args)
    {
        $options = get_option($this->option_name);
        $value = $options[$args['field_id']] ?? '';
        $type = $args['type'] ?? 'text';

        echo '<input type="' . esc_attr($type) . '" name="' .
             esc_attr($this->option_name) . '[' . esc_attr($args['field_id']) . ']" value="' .
             esc_attr($value) . '" class="regular-text" />';

        if ($args['field_id'] === 'brevo_api_key') {
            echo '<p class="description">Disponible dans <strong>Brevo > Paramètres > Clés API</strong>.</p>';
        }
        if ($args['field_id'] === 'brevo_list_id') {
            echo '<p class="description">ID numérique de la liste (ex: 123) visible dans l’URL de la liste.</p>';
        }
    }

    public function admin_page()
    {
        ?>
        <div class="wrap">
            <h1>Configuration Brevo</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('brevo_config_group');
                do_settings_sections('brevo-config');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /* ---------- Partie API ---------- */

    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl . $endpoint;
        $apiKey = $this->get_api_key();

        $headers = [
            'api-key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30
        ]);

        if (!empty($data)) {
            $json_data = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json_data === false) {
                throw new Exception("Erreur encodage JSON : " . json_last_error_msg());
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("Erreur cURL : $error");
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Erreur JSON : " . json_last_error_msg());
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("Erreur API Brevo ($httpCode) : " . ($decoded['message'] ?? $response));
        }

        return $decoded;
    }

    /* ---------- Getters WordPress ---------- */

    public function get_api_key(): string
    {
        $options = get_option($this->option_name);
        return $options['brevo_api_key'] ?? '';
    }

    public function get_list_id(): int
    {
        $options = get_option($this->option_name);
        return intval($options['brevo_list_id'] ?? 0);
    }

    /* ---------- Méthodes API ---------- */

    public function addContactToList(string $email, int $listId = null, array $attributes = []): bool
    {
        try {
            $listId = $listId ?? $this->get_list_id();
            
            if (empty($listId)) {
                error_log("Brevo: Aucune liste spécifiée pour l'ajout du contact $email");
                return false;
            }
            
            $data = [
                'email' => $email,
                'attributes' => $attributes,
                'listIds' => [$listId],
                'updateEnabled' => true
            ];
            
            // Log des données envoyées à Brevo
            error_log("Brevo: Envoi du contact $email avec attributs: " . json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            
            $result = $this->request('POST', '/contacts', $data);
            error_log("Brevo: Contact $email ajouté avec succès");
            return true;
        } catch (Exception $e) {
            error_log("Brevo: Erreur lors de l'ajout du contact $email: " . $e->getMessage());
            return false;
        }
    }

    public function removeContactFromList(string $email, int $listId = null): bool
    {
        $listId = $listId ?? $this->get_list_id();
        $data = ['emails' => [$email]];
        $this->request('POST', "/contacts/lists/{$listId}/contacts/remove", $data);
        return true;
    }

    public function getContactsFromList(int $listId = null, int $limit = 500, int $offset = 0): array
    {
        $listId = $listId ?? $this->get_list_id();
        return $this->request('GET', "/contacts?listIds=$listId&limit=$limit&offset=$offset");
    }

    public function getContact(string $email): array
    {
        return $this->request('GET', '/contacts/' . urlencode($email));
    }
    
    /**
     * Vérifie si un contact est présent dans une liste Brevo spécifique
     * 
     * @param string $email Email du contact à vérifier
     * @param int|null $listId ID de la liste Brevo (facultatif, utilise la liste par défaut si non spécifié)
     * @return bool True si le contact est présent dans la liste, false sinon
     */
    public function isContactInList(string $email, int $listId = null): bool
    {
        try {
            $listId = $listId ?? $this->get_list_id();
            
            if (empty($listId)) {
                error_log("Brevo: Aucune liste spécifiée pour vérifier le contact $email");
                return false;
            }
            
            // Récupérer les informations du contact
            $contactInfo = $this->getContact($email);
            
            // Vérifier si le contact existe et s'il appartient à la liste spécifiée
            if (isset($contactInfo['listIds']) && is_array($contactInfo['listIds'])) {
                return in_array($listId, $contactInfo['listIds']);
            }
            
            return false;
        } catch (Exception $e) {
            // Si une erreur 404 est renvoyée, cela signifie que le contact n'existe pas
            if (strpos($e->getMessage(), '404') !== false) {
                return false;
            }
            
            error_log("Brevo: Erreur lors de la vérification du contact $email: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Récupère tous les emails de la liste Brevo
     * 
     * @param int|null $listId ID de la liste Brevo (facultatif, utilise la liste par défaut si non spécifié)
     * @return array Tableau des emails présents dans la liste Brevo
     */
    public function getAllContactEmails(int $listId = null): array
    {
        $listId = $listId ?? $this->get_list_id();
        $emails = [];
        $offset = 0;
        $limit = 50; // Récupérer par lots de 50 contacts (limite acceptée par l'API Brevo)
        $total = 0;
        
        do {
            $response = $this->getContactsFromList($listId, $limit, $offset);
            
            if (!isset($response['contacts']) || !is_array($response['contacts'])) {
                break;
            }
            
            foreach ($response['contacts'] as $contact) {
                if (isset($contact['email'])) {
                    $emails[] = strtolower($contact['email']); // Stocker en minuscules pour comparaison insensible à la casse
                }
            }
            
            $total = $response['count'] ?? 0;
            $offset += $limit;
            
        } while (count($emails) < $total && isset($response['contacts']) && count($response['contacts']) > 0);
        
        return $emails;
    }
}

/* ---------- Initialisation ---------- */
new BrevoConnector();