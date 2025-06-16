<?php
/**
 * WP-CLI command for importing WooCommerce taxonomies from CSV file
 * 
 * Usage:
 * wp taxonomy-import path/to/file.csv --mode=update|replace [--dry-run] [--verbose] [--delimiter=,] [--skip-lines=0] [--batch-size=100]
 */

if (!defined('WP_CLI') || !WP_CLI) {
    exit('This script can only be run via WP-CLI');
}

class WC_Taxonomy_Import_Command {
    
    private $stats = [
        'total_rows' => 0,
        'products_found' => 0,
        'products_not_found' => 0,
        'taxonomies_updated' => 0,
        'taxonomies_skipped' => 0,
        'terms_per_taxonomy' => []
    ];
    
    private $errors = [
        'products_not_found' => [],
        'terms_not_found' => [],
        'duplicate_terms' => [],
        'already_existing' => []
    ];
    
    private $start_time;
    private $log_file;
    private $verbose = false;
    private $dry_run = false;
    private $mode = 'update'; // update or replace
    private $delimiter = ',';
    private $skip_lines = 0;
    private $batch_size = 100;
    private $max_file_size = 50 * 1024 * 1024; // 50MB
    
    /**
     * Import taxonomies from CSV file
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to CSV file
     *
     * --mode=<mode>
     * : Operation mode: update (add to existing) or replace (replace all)
     * ---
     * default: update
     * options:
     *   - update
     *   - replace
     * ---
     *
     * [--dry-run]
     * : Test run without making changes
     *
     * [--verbose]
     * : Verbose output
     *
     * [--delimiter=<delimiter>]
     * : CSV delimiter
     * ---
     * default: ,
     * ---
     *
     * [--skip-lines=<lines>]
     * : Number of lines to skip at the beginning of file
     * ---
     * default: 0
     * ---
     *
     * [--batch-size=<size>]
     * : Number of rows to process at once
     * ---
     * default: 100
     * ---
     *
     * ## EXAMPLES
     *
     *     wp taxonomy-import export.csv --mode=update --verbose
     *     wp taxonomy-import export.csv --mode=replace --dry-run
     *
     */
    public function __invoke($args, $assoc_args) {
        $this->start_time = microtime(true);
        
        // BLOCK 1: Initialization and general checks
        if (!$this->block1_initialization($args, $assoc_args)) {
            return;
        }
        
        // BLOCK 2: CSV file validation
        if (!$this->block2_csv_validation($args[0])) {
            return;
        }
        
        // BLOCK 3: Data preparation
        $prepared_data = $this->block3_data_preparation($args[0]);
        if ($prepared_data === false) {
            return;
        }
        
        // BLOCK 4: Database operations
        if (!$this->dry_run) {
            if (!$this->block4_database_operations($prepared_data)) {
                return;
            }
        } else {
            WP_CLI::log("DRY RUN MODE: No changes will be made");
        }
        
        // BLOCK 5: Finalization and reporting
        $this->block5_finalization($args[0]);
    }
    
    /**
     * BLOCK 1: Initialization and general checks
     */
    private function block1_initialization($args, $assoc_args) {
        // Parameter validation
        if (empty($args[0])) {
            WP_CLI::error('CSV file path is required');
            return false;
        }
        
        // Set parameters
        $this->mode = isset($assoc_args['mode']) ? $assoc_args['mode'] : 'update';
        $this->dry_run = isset($assoc_args['dry-run']);
        $this->verbose = isset($assoc_args['verbose']);
        $this->delimiter = isset($assoc_args['delimiter']) ? $assoc_args['delimiter'] : ',';
        $this->skip_lines = isset($assoc_args['skip-lines']) ? intval($assoc_args['skip-lines']) : 0;
        $this->batch_size = isset($assoc_args['batch-size']) ? intval($assoc_args['batch-size']) : 100;
        
        // Parameter validation
        if (!in_array($this->mode, ['update', 'replace'])) {
            WP_CLI::error('Mode must be either update or replace');
            return false;
        }
        
        if ($this->batch_size < 1 || $this->batch_size > 1000) {
            WP_CLI::error('Batch size must be between 1 and 1000');
            return false;
        }
        
        // WooCommerce check
        if (!class_exists('WooCommerce')) {
            WP_CLI::error('WooCommerce not found');
            return false;
        }
        
        // Create log file
        $log_dir = WP_CONTENT_DIR . '/uploads/taxonomy-import-logs/';
        if (!is_dir($log_dir)) {
            if (!wp_mkdir_p($log_dir)) {
                WP_CLI::error('Failed to create log directory: ' . $log_dir);
                return false;
            }
        }
        
        $this->log_file = $log_dir . 'taxonomy_update_results_' . date('Y-m-d_H-i-s') . '.log';
        if (!is_writable($log_dir)) {
            WP_CLI::error('No write permissions for log directory: ' . $log_dir);
            return false;
        }
        
        if ($this->verbose) {
            WP_CLI::log('Initialization completed');
            WP_CLI::log('Mode: ' . $this->mode);
            WP_CLI::log('Log file: ' . $this->log_file);
        }
        
        return true;
    }
    
    /**
     * BLOCK 2: CSV file validation
     */
    private function block2_csv_validation($file_path) {
        // File existence check
        if (!file_exists($file_path)) {
            WP_CLI::error('File not found: ' . $file_path);
            return false;
        }
        
        // File size check
        $file_size = filesize($file_path);
        if ($file_size > $this->max_file_size) {
            WP_CLI::error('File too large: ' . round($file_size / 1024 / 1024, 2) . 'MB. Maximum: ' . round($this->max_file_size / 1024 / 1024, 2) . 'MB');
            return false;
        }
        
        // Encoding check
        $file_content = file_get_contents($file_path, false, null, 0, 1024);
        if (!mb_check_encoding($file_content, 'UTF-8')) {
            WP_CLI::warning('File may not be in UTF-8 encoding');
        }
        
        // Basic CSV structure check
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            WP_CLI::error('Failed to open file for reading');
            return false;
        }
        
        // Skip lines if needed
        for ($i = 0; $i < $this->skip_lines; $i++) {
            fgetcsv($handle, 0, $this->delimiter);
        }
        
        // Header validation
        $headers = fgetcsv($handle, 0, $this->delimiter);
        if (!$headers || empty($headers[0])) {
            WP_CLI::error('Failed to read CSV headers or first column is empty');
            fclose($handle);
            return false;
        }
        
        // First column must be SKU
        $headers[0] = trim($headers[0]);
        if (strtolower($headers[0]) !== 'sku') {
            WP_CLI::error('First column must be SKU, found: ' . $headers[0]);
            fclose($handle);
            return false;
        }
        
        // Check for taxonomy columns
        if (count($headers) < 2) {
            WP_CLI::error('CSV must contain at least 2 columns (SKU + taxonomies)');
            fclose($handle);
            return false;
        }
        
        fclose($handle);
        
        if ($this->verbose) {
            WP_CLI::log('CSV validation completed');
            WP_CLI::log('Columns found: ' . count($headers));
            WP_CLI::log('Taxonomies: ' . implode(', ', array_slice($headers, 1)));
        }
        
        return true;
    }
    
    /**
     * BLOCK 3: Data preparation
     */
    private function block3_data_preparation($file_path) {
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            WP_CLI::error('Failed to open file for reading');
            return false;
        }
        
        // Skip lines if needed
        for ($i = 0; $i < $this->skip_lines; $i++) {
            fgetcsv($handle, 0, $this->delimiter);
        }
        
        // Read headers
        $headers = fgetcsv($handle, 0, $this->delimiter);
        $headers = array_map('trim', $headers);
        $taxonomy_columns = array_slice($headers, 1);
        
        // Check taxonomy existence
        foreach ($taxonomy_columns as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                WP_CLI::error('Taxonomy does not exist: ' . $taxonomy);
                fclose($handle);
                return false;
            }
        }
        
        $prepared_data = [];
        $sku_duplicates = [];
        $line_number = $this->skip_lines + 2; // +1 for headers, +1 for numbering from 1
        
        // Create progress bar
        $total_lines = count(file($file_path)) - $this->skip_lines - 1;
        $progress = \WP_CLI\Utils\make_progress_bar('Preparing data', $total_lines);
        
        while (($row = fgetcsv($handle, 0, $this->delimiter)) !== false) {
            $this->stats['total_rows']++;
            
            // Handle empty rows
            if (empty(array_filter($row))) {
                $line_number++;
                $progress->tick();
                continue;
            }
            
            // Check column count
            if (count($row) !== count($headers)) {
                WP_CLI::warning("Line $line_number: incorrect number of columns (" . count($row) . " instead of " . count($headers) . ")");
            }
            
            $sku = trim($row[0]);
            if (empty($sku)) {
                WP_CLI::warning("Line $line_number: empty SKU");
                $line_number++;
                $progress->tick();
                continue;
            }
            
            // Check for duplicate SKUs
            if (isset($prepared_data[$sku])) {
                $sku_duplicates[] = $sku;
            }
            
            // Find product by SKU
            $product_id = wc_get_product_id_by_sku($sku);
            if (!$product_id) {
                $this->errors['products_not_found'][] = $sku;
                $line_number++;
                $progress->tick();
                continue;
            }
            
            $this->stats['products_found']++;
            
            // Prepare taxonomy data
            $taxonomies_data = [];
            for ($i = 1; $i < count($headers) && $i < count($row); $i++) {
                $taxonomy = $taxonomy_columns[$i - 1];
                $term_value = trim($row[$i]);
                
                if (empty($term_value)) {
                    continue;
                }
                
                // Check term existence
                $term = get_term_by('name', $term_value, $taxonomy);
                if (!$term) {
                    $this->errors['terms_not_found'][] = $taxonomy . ': "' . $term_value . '"';
                    continue;
                }
                
                // Check for duplicate terms
                $duplicate_terms = get_terms([
                    'taxonomy' => $taxonomy,
                    'name' => $term_value,
                    'hide_empty' => false
                ]);
                
                if (count($duplicate_terms) > 1) {
                    $term_ids = array_map(function($t) { return $t->term_id; }, $duplicate_terms);
                    $this->errors['duplicate_terms'][] = $taxonomy . ': "' . $term_value . '" (IDs: ' . implode(', ', $term_ids) . ')';
                    continue;
                }
                
                // Check existing relations (only for update mode)
                if ($this->mode === 'update') {
                    $existing_terms = wp_get_post_terms($product_id, $taxonomy, ['fields' => 'ids']);
                    if (in_array($term->term_id, $existing_terms)) {
                        $this->errors['already_existing'][] = $sku . ': ' . $taxonomy . ' "' . $term_value . '"';
                        $this->stats['taxonomies_skipped']++;
                        continue;
                    }
                }
                
                $taxonomies_data[$taxonomy][] = $term->term_id;
                
                // Taxonomy statistics
                if (!isset($this->stats['terms_per_taxonomy'][$taxonomy])) {
                    $this->stats['terms_per_taxonomy'][$taxonomy] = 0;
                }
                $this->stats['terms_per_taxonomy'][$taxonomy]++;
            }
            
            if (!empty($taxonomies_data)) {
                $prepared_data[$sku] = [
                    'product_id' => $product_id,
                    'taxonomies' => $taxonomies_data
                ];
            }
            
            $line_number++;
            $progress->tick();
        }
        
        $progress->finish();
        fclose($handle);
        
        // Handle SKU duplicates
        if (!empty($sku_duplicates)) {
            WP_CLI::warning('Duplicate SKUs found: ' . implode(', ', array_unique($sku_duplicates)));
        }
        
        if ($this->verbose) {
            WP_CLI::log('Data preparation completed');
            WP_CLI::log('Products to process: ' . count($prepared_data));
        }
        
        return $prepared_data;
    }
    
    /**
     * BLOCK 4: Database operations
     */
    private function block4_database_operations($prepared_data) {
        if (empty($prepared_data)) {
            WP_CLI::warning('No data to process');
            return true;
        }
        
        $progress = \WP_CLI\Utils\make_progress_bar('Updating products', count($prepared_data));
        
        foreach ($prepared_data as $sku => $data) {
            $product_id = $data['product_id'];
            $taxonomies = $data['taxonomies'];
            
            try {
                foreach ($taxonomies as $taxonomy => $term_ids) {
                    if ($this->mode === 'replace') {
                        // Replace all terms
                        $result = wp_set_object_terms($product_id, $term_ids, $taxonomy, false);
                    } else {
                        // Add to existing
                        $result = wp_set_object_terms($product_id, $term_ids, $taxonomy, true);
                    }
                    
                    if (is_wp_error($result)) {
                        WP_CLI::error('Database error for product ' . $sku . ': ' . $result->get_error_message());
                        return false;
                    }
                    
                    $this->stats['taxonomies_updated'] += count($term_ids);
                }
                
                // Clear product cache
                clean_post_cache($product_id);
                
            } catch (Exception $e) {
                WP_CLI::error('Critical database error for product ' . $sku . ': ' . $e->getMessage());
                return false;
            }
            
            $progress->tick();
        }
        
        $progress->finish();
        
        if ($this->verbose) {
            WP_CLI::log('Database update completed');
        }
        
        return true;
    }
    
    /**
     * BLOCK 5: Finalization and reporting
     */
    private function block5_finalization($csv_file) {
        $execution_time = microtime(true) - $this->start_time;
        $this->stats['products_not_found'] = count($this->errors['products_not_found']);
        
        // Generate report
        $report = $this->generate_report($csv_file, $execution_time);
        
        // Write to log file
        file_put_contents($this->log_file, $report);
        
        // Terminal output
        WP_CLI::log('');
        WP_CLI::log('=== IMPORT RESULTS ===');
        WP_CLI::log('Total rows processed: ' . $this->stats['total_rows']);
        WP_CLI::log('Products found: ' . $this->stats['products_found']);
        WP_CLI::log('Products not found: ' . $this->stats['products_not_found']);
        WP_CLI::log('Taxonomies updated: ' . $this->stats['taxonomies_updated']);
        WP_CLI::log('Taxonomies skipped: ' . $this->stats['taxonomies_skipped']);
        WP_CLI::log('Execution time: ' . round($execution_time, 2) . ' sec');
        WP_CLI::log('');
        
        if (!empty($this->errors['products_not_found'])) {
            WP_CLI::warning('Products not found: ' . count($this->errors['products_not_found']));
        }
        
        if (!empty($this->errors['terms_not_found'])) {
            WP_CLI::warning('Terms not found: ' . count($this->errors['terms_not_found']));
        }
        
        if (!empty($this->errors['duplicate_terms'])) {
            WP_CLI::warning('Duplicate terms found: ' . count($this->errors['duplicate_terms']));
        }
        
        WP_CLI::success('Detailed report saved: ' . $this->log_file);
        
        // Clear WordPress cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        // Update term counts
        foreach ($this->stats['terms_per_taxonomy'] as $taxonomy => $count) {
            wp_update_term_count_now([], $taxonomy);
        }
    }
    
    /**
     * Generate detailed report
     */
    private function generate_report($csv_file, $execution_time) {
        $report = "=== TAXONOMY UPDATE RESULTS ===\n";
        $report .= "Date: " . date('Y-m-d H:i:s') . "\n";
        $report .= "CSV File: " . basename($csv_file) . "\n";
        $report .= "Mode: " . strtoupper($this->mode) . "\n";
        $report .= "Execution Time: " . round($execution_time, 2) . " seconds\n";
        $report .= "\n";
        
        $report .= "STATISTICS:\n";
        $report .= "- Total rows processed: " . $this->stats['total_rows'] . "\n";
        $report .= "- Products found: " . $this->stats['products_found'] . "\n";
        $report .= "- Products not found: " . $this->stats['products_not_found'] . "\n";
        $report .= "- Taxonomies updated: " . $this->stats['taxonomies_updated'] . "\n";
        $report .= "- Taxonomies skipped (already existed): " . $this->stats['taxonomies_skipped'] . "\n";
        
        if (!empty($this->stats['terms_per_taxonomy'])) {
            $report .= "- Terms per taxonomy:\n";
            foreach ($this->stats['terms_per_taxonomy'] as $taxonomy => $count) {
                $report .= "  * $taxonomy: $count\n";
            }
        }
        
        if ($this->stats['total_rows'] > 0) {
            $rate = round($this->stats['total_rows'] / $execution_time, 2);
            $report .= "- Processing rate: $rate rows/sec\n";
        }
        
        $report .= "\n";
        
        if (!empty($this->errors['products_not_found']) || 
            !empty($this->errors['terms_not_found']) || 
            !empty($this->errors['duplicate_terms']) || 
            !empty($this->errors['already_existing'])) {
            
            $report .= "ERRORS:\n";
            
            if (!empty($this->errors['products_not_found'])) {
                $report .= "Products not found:\n";
                foreach ($this->errors['products_not_found'] as $sku) {
                    $report .= "- $sku\n";
                }
                $report .= "\n";
            }
            
            if (!empty($this->errors['terms_not_found'])) {
                $report .= "Terms not found:\n";
                foreach ($this->errors['terms_not_found'] as $term) {
                    $report .= "- $term\n";
                }
                $report .= "\n";
            }
            
            if (!empty($this->errors['duplicate_terms'])) {
                $report .= "Duplicate terms found:\n";
                foreach ($this->errors['duplicate_terms'] as $term) {
                    $report .= "- $term\n";
                }
                $report .= "\n";
            }
            
            if (!empty($this->errors['already_existing'])) {
                $report .= "Already existing taxonomies (skipped):\n";
                foreach ($this->errors['already_existing'] as $existing) {
                    $report .= "- $existing\n";
                }
                $report .= "\n";
            }
        }
        
        return $report;
    }
}

// Register command
WP_CLI::add_command('taxonomy-import', 'WC_Taxonomy_Import_Command');