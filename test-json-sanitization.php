<?php
/**
 * Test script pour valider la fonction de sanitisation JSON
 * Ce script teste différents cas problématiques qui peuvent causer des erreurs JSON
 */

// Inclure la classe ContactSynchronizer pour accéder à la fonction sanitize_for_json
require_once __DIR__ . '/includes/class-contact-synchronizer.php';

class JsonSanitizationTest {
    
    private function sanitize_for_json($value): string
    {
        if (empty($value)) {
            return '';
        }
        
        // Convertir en string si ce n'est pas déjà le cas
        $value = (string) $value;
        
        // Supprimer les caractères de contrôle qui peuvent causer des erreurs JSON
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        
        // Supprimer les caractères Unicode problématiques (BOM, ZWNBSP, etc.)
        $value = preg_replace('/[\x{FEFF}\x{FFFE}\x{200B}\x{200C}\x{200D}\x{2060}]/u', '', $value);
        
        // Échapper les caractères spéciaux JSON problématiques
        $value = str_replace(['"', "\n", "\r", "\t", "\\"], ['\"', '\\n', '\\r', '\\t', '\\\\'], $value);
        
        // Nettoyer les caractères invisibles et les espaces multiples
        $value = preg_replace('/\s+/', ' ', trim($value));
        
        // S'assurer que la chaîne est en UTF-8 valide
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'auto');
        }
        
        // Vérifier que la valeur peut être encodée en JSON
        $test_json = json_encode($value, JSON_UNESCAPED_UNICODE);
        if ($test_json === false) {
            // Si l'encodage JSON échoue, utiliser une approche plus agressive
            $value = filter_var($value, FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_HIGH);
            $value = preg_replace('/[^\x20-\x7E\x{00A0}-\x{FFFF}]/u', '', $value);
        }
        
        return $value;
    }
    
    public function runTests() {
        echo "=== Test de sanitisation JSON ===\n\n";
        
        // Cas de test problématiques
        $test_cases = [
            // Caractères de contrôle
            "Nom avec\x00caractère null" => "Caractères de contrôle",
            "Nom avec\x08backspace" => "Caractères de contrôle",
            "Nom avec\x1Fcaractère US" => "Caractères de contrôle",
            
            // Caractères JSON problématiques
            'Nom avec "guillemets"' => "Guillemets",
            "Nom avec\nnouvelle ligne" => "Nouvelle ligne",
            "Nom avec\tretour chariot" => "Tabulation",
            "Nom avec\\backslash" => "Backslash",
            
            // Caractères Unicode problématiques
            "\xEF\xBB\xBFNom avec BOM" => "BOM UTF-8",
            "Nom\x{200B}avec ZWSP" => "Zero Width Space",
            "Nom\x{FEFF}avec ZWNBSP" => "Zero Width No-Break Space",
            
            // Espaces multiples
            "Nom   avec    espaces     multiples" => "Espaces multiples",
            "  Nom avec espaces début/fin  " => "Espaces début/fin",
            
            // Caractères valides
            "Nom normal" => "Nom normal",
            "Nom avec accents éàüñ" => "Accents",
            "Nom avec émojis 🚀✨" => "Émojis",
            
            // Cas extrêmes
            "" => "Chaîne vide",
            "   " => "Espaces seulement",
            "A" => "Un caractère",
        ];
        
        $passed = 0;
        $failed = 0;
        
        foreach ($test_cases as $input => $description) {
            echo "Test: {$description}\n";
            echo "Input: " . json_encode($input) . "\n";
            
            try {
                $sanitized = $this->sanitize_for_json($input);
                echo "Sanitized: " . json_encode($sanitized) . "\n";
                
                // Tester l'encodage JSON
                $json_result = json_encode($sanitized, JSON_UNESCAPED_UNICODE);
                if ($json_result === false) {
                    echo "❌ ÉCHEC: Impossible d'encoder en JSON après sanitisation\n";
                    echo "Erreur JSON: " . json_last_error_msg() . "\n";
                    $failed++;
                } else {
                    echo "✅ SUCCÈS: Encodage JSON réussi\n";
                    $passed++;
                }
                
                // Tester dans un contexte d'attributs Brevo
                $attributes = [
                    'FIRSTNAME' => $sanitized,
                    'LASTNAME' => 'Test',
                    'NELIS_ID' => 12345
                ];
                
                $full_json = json_encode($attributes, JSON_UNESCAPED_UNICODE);
                if ($full_json === false) {
                    echo "❌ ÉCHEC: Impossible d'encoder les attributs complets\n";
                    $failed++;
                } else {
                    echo "✅ SUCCÈS: Attributs complets encodés\n";
                }
                
            } catch (Exception $e) {
                echo "❌ EXCEPTION: " . $e->getMessage() . "\n";
                $failed++;
            }
            
            echo "---\n\n";
        }
        
        echo "=== Résultats ===\n";
        echo "Tests réussis: {$passed}\n";
        echo "Tests échoués: {$failed}\n";
        echo "Total: " . ($passed + $failed) . "\n";
        
        if ($failed === 0) {
            echo "🎉 Tous les tests sont passés!\n";
        } else {
            echo "⚠️  {$failed} test(s) ont échoué.\n";
        }
    }
}

// Exécuter les tests
$tester = new JsonSanitizationTest();
$tester->runTests();
