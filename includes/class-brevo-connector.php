<?php
/**
 * Plugin Name: Brevo Connector Config
 * Description: Configuration simple pour Brevo (clé API + ID de liste)
 * Version: 1.0.0
 * Author: GEHIN Nicolas
 */

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
        add_options_page(
            'Brevo Config',
            'Brevo Config',
            'manage_options',
            'brevo-config',
            [$this, 'admin_page']
        );
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
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
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
        $listId = $listId ?? $this->get_list_id();
        $data = [
            'email' => $email,
            'attributes' => $attributes,
            'listIds' => [$listId],
            'updateEnabled' => true
        ];

        $this->request('POST', '/contacts', $data);
        return true;
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
}

/* ---------- Initialisation ---------- */
new BrevoConnector();