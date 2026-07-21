<?php
/**
 * Resource Manager for PDFs and Documents
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Resource_Manager {
    
    /**
     * Upload and process a resource file — extracts text and stores as plain text (no file saved to disk)
     */
    public static function upload_resource($file) {
        // Check for upload errors
        if (!isset($file['error'])) {
            return [
                'success' => false,
                'error' => 'Invalid file upload'
            ];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error_messages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'Upload blocked by server extension',
            ];
            $error_msg = isset($error_messages[$file['error']]) ? $error_messages[$file['error']] : 'Unknown upload error';
            return [
                'success' => false,
                'error' => $error_msg
            ];
        }

        // File size limit (10MB)
        $max_size = 10 * 1024 * 1024;
        if (isset($file['size']) && $file['size'] > $max_size) {
            return [
                'success' => false,
                'error' => 'File too large. Maximum size is 10MB.'
            ];
        }

        $allowed_types = ['pdf', 'doc', 'docx', 'txt'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($file_ext, $allowed_types)) {
            return [
                'success' => false,
                'error' => 'Invalid file type. Allowed: PDF, DOC, DOCX, TXT'
            ];
        }

        // MIME type validation (defense in depth — don't trust extension alone)
        $allowed_mimes = [
            'pdf'  => ['application/pdf'],
            'doc'  => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'txt'  => ['text/plain'],
        ];
        if (isset($file['tmp_name']) && function_exists('finfo_file')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detected_mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            $valid_mimes = $allowed_mimes[$file_ext] ?? [];
            // text/plain is also valid for txt-like content in other formats
            if (!in_array($detected_mime, $valid_mimes, true) && $detected_mime !== 'text/plain') {
                // Allow application/octet-stream as fallback (some servers can't detect .doc MIME)
                if ($detected_mime !== 'application/octet-stream') {
                    Chatzio_Logger::log_warning('MIME mismatch on upload', [
                        'filename' => $file['name'],
                        'extension' => $file_ext,
                        'detected_mime' => $detected_mime,
                    ]);
                    return [
                        'success' => false,
                        'error' => 'File content does not match its extension. Please upload a valid file.'
                    ];
                }
            }
        }

        // Extract text content from the temp file (no permanent file saved to disk)
        $content = self::extract_text_from_file($file['tmp_name'], $file_ext);

        // If extraction failed, use placeholder text
        if ($content === false || $content === null) {
            $content = '[Content extraction not available for this file type]';
        }

        // Ensure content is a string
        $content = is_string($content) ? $content : '';

        // Validate extracted content - check if it looks like readable text
        $content = self::validate_extracted_content($content);

        $filename = sanitize_file_name($file['name']);

        // Save to database (plain text only, no file on disk)
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_resources';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) {
            return [
                'success' => false,
                'error' => 'Database table not found. Please deactivate and reactivate the plugin.'
            ];
        }

        $result = $wpdb->insert($table, [
            'title' => sanitize_text_field($file['name']),
            'filename' => $filename,
            'file_path' => '',
            'file_type' => $file_ext,
            'content' => $content,
            'status' => 'active',
            'uploaded_by' => get_current_user_id(),
        ]);

        if ($result === false) {
            Chatzio_Logger::log_error('Resource upload DB insert failed', [
                'error' => $wpdb->last_error,
                'filename' => $filename,
            ]);
            return [
                'success' => false,
                'error' => 'Failed to save resource to database. Please try again.'
            ];
        }

        Chatzio_Logger::log_info('Resource uploaded', [
            'filename' => $filename,
            'type' => $file_ext,
            'content_length' => strlen($content)
        ]);

        return [
            'success' => true,
            'resource_id' => $wpdb->insert_id,
            'filename' => $filename,
            'message' => 'File uploaded successfully'
        ];
    }
    
    /**
     * Extract text from file
     */
    private static function extract_text_from_file($file_path, $file_type) {
        try {
            switch ($file_type) {
                case 'txt':
                    $content = @file_get_contents($file_path);
                    return $content !== false ? $content : '';
                    
                case 'pdf':
                    return self::extract_text_from_pdf($file_path);
                    
                case 'doc':
                case 'docx':
                    return self::extract_text_from_doc($file_path, $file_type);
                    
                default:
                    return '';
            }
        } catch (Exception $e) {
            Chatzio_Logger::log_error('Text extraction failed', [
                'file' => $file_path,
                'type' => $file_type,
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }
    
    /**
     * Extract text from PDF
     */
    private static function extract_text_from_pdf($file_path) {
        $text = '';
        
        // Try using pdftotext command if available
        if (function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
            $output = @shell_exec('pdftotext ' . escapeshellarg($file_path) . ' - 2>/dev/null');
            if (!empty($output)) {
                return trim($output);
            }
        }
        
        // Fallback: basic PDF text extraction
        $content = @file_get_contents($file_path);
        if ($content === false) {
            return '';
        }
        
        // Try to extract text between stream and endstream
        if (preg_match_all('/stream\s*(.+?)\s*endstream/s', $content, $streams)) {
            foreach ($streams[1] as $stream) {
                // Try to decode if it's compressed
                $decoded = @gzuncompress($stream);
                if ($decoded !== false) {
                    $stream = $decoded;
                }
                // Extract text content
                if (preg_match_all('/\(([^\)]+)\)/', $stream, $matches)) {
                    $text .= implode(' ', $matches[1]) . ' ';
                }
            }
        }
        
        // Also try simple parentheses extraction
        if (empty($text) && preg_match_all('/\(([^\)]{2,})\)/i', $content, $matches)) {
            $text = implode(' ', $matches[1]);
        }
        
        // Clean up the text
        $text = preg_replace('/[^\x20-\x7E\s]/', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        
        return trim($text);
    }
    
    /**
     * Extract text from DOC/DOCX
     */
    private static function extract_text_from_doc($file_path, $file_type) {
        if ($file_type === 'docx') {
            return self::extract_text_from_docx($file_path);
        }
        
        // For .doc files, try antiword if available
        if (function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
            $output = @shell_exec('antiword ' . escapeshellarg($file_path) . ' 2>/dev/null');
            if (!empty($output)) {
                return trim($output);
            }
        }
        
        // Fallback: try to read raw content
        $content = @file_get_contents($file_path);
        if ($content === false) {
            return '';
        }
        
        // Extract readable text from binary
        $text = '';
        if (preg_match_all('/[\x20-\x7E]{4,}/', $content, $matches)) {
            $text = implode(' ', $matches[0]);
        }
        
        return trim($text);
    }
    
    /**
     * Extract text from DOCX
     */
    private static function extract_text_from_docx($file_path) {
        if (!class_exists('ZipArchive')) {
            return '[ZipArchive not available - cannot read DOCX files]';
        }
        
        $zip = new ZipArchive();
        $text = '';
        
        if ($zip->open($file_path) === true) {
            // Try to get document.xml
            $xml_content = $zip->getFromName('word/document.xml');
            $zip->close();
            
            if ($xml_content) {
                // Strip XML tags and get text content
                // First, handle paragraph breaks
                $xml_content = preg_replace('/<w:p[^>]*>/i', "\n", $xml_content);
                $xml_content = preg_replace('/<w:br[^>]*>/i', "\n", $xml_content);
                
                // Extract text from w:t tags
                if (preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/i', $xml_content, $matches)) {
                    $text = implode('', $matches[1]);
                }
                
                // If that didn't work, try stripping all tags
                if (empty(trim($text))) {
                    $text = strip_tags($xml_content);
                }
                
                // Clean up
                $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
                $text = preg_replace('/\s+/', ' ', $text);
                $text = trim($text);
            }
        }
        
        return $text;
    }
    
    /**
     * Get all resources
     */
    public static function get_resources($status = 'active') {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_resources';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) {
            return [];
        }
        
        $query = "SELECT * FROM $table WHERE status = %s ORDER BY uploaded_at DESC";
        $results = $wpdb->get_results($wpdb->prepare($query, $status), ARRAY_A);
        
        return $results ? $results : [];
    }
    
    /**
     * Get resource by ID
     */
    public static function get_resource($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_resources';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $id
        ), ARRAY_A);
    }
    
    /**
     * Delete resource
     */
    public static function delete_resource($id) {
        $resource = self::get_resource($id);
        
        if (!$resource) {
            return false;
        }
        
        // Delete file
        if (!empty($resource['file_path']) && file_exists($resource['file_path'])) {
            @unlink($resource['file_path']);
        }
        
        // Delete from database
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_resources';
        
        return $wpdb->delete($table, ['id' => $id]) !== false;
    }
    
    /**
     * Update resource title and content
     */
    public static function update_resource($id, $title, $content) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_resources';

        $resource = self::get_resource($id);
        if (!$resource) {
            return false;
        }

        return $wpdb->update(
            $table,
            [
                'title'   => sanitize_text_field($title),
                'content' => $content,
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        ) !== false;
    }

    /**
     * Validate extracted content - check if it's readable text
     */
    private static function validate_extracted_content($content) {
        if (empty($content)) {
            return '[No text content could be extracted from this file]';
        }
        
        // Check if content has too many non-printable or special characters
        // This indicates failed extraction (binary data instead of text)
        $sample = substr($content, 0, 500);
        
        // Count readable characters (letters, numbers, common punctuation, whitespace)
        $readable_chars = preg_match_all('/[a-zA-Z0-9\s\.,!?\-\'"():;]/', $sample);
        $total_chars = strlen($sample);
        
        if ($total_chars > 0) {
            $readable_ratio = $readable_chars / $total_chars;
            
            // If less than 60% of characters are readable, extraction likely failed
            if ($readable_ratio < 0.6) {
                Chatzio_Logger::log_error('Content extraction produced unreadable content', [
                    'readable_ratio' => $readable_ratio,
                    'sample' => substr($sample, 0, 100)
                ]);
                return '[WARNING: Text extraction failed - file may be image-based or encrypted. Please upload a text-based document or .txt file for best results.]';
            }
        }
        
        return $content;
    }
    
    /**
     * Protect upload directory from direct web access
     */
    private static function protect_directory($dir) {
        // .htaccess for Apache
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
        }
        // index.php for fallback (Nginx, LiteSpeed, etc.)
        $index = $dir . '/index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
    }

    /**
     * Search resources
     */
    public static function search_resources($query) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_resources';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) {
            return [];
        }
        
        $search_term = '%' . $wpdb->esc_like($query) . '%';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table 
            WHERE (title LIKE %s OR content LIKE %s) AND status = 'active'
            ORDER BY uploaded_at DESC
            LIMIT 5",
            $search_term,
            $search_term
        ), ARRAY_A);
        
        if (!$results) {
            return [];
        }
        
        $formatted_results = [];
        foreach ($results as $result) {
            $content = isset($result['content']) ? $result['content'] : '';
            $formatted_results[] = [
                'title' => $result['title'],
                'content' => strlen($content) > 500 ? substr($content, 0, 500) . '...' : $content,
            ];
        }
        
        return $formatted_results;
    }
}
