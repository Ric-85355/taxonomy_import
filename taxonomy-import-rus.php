<?php
/**
 * WP-CLI команда для импорта таксономий WooCommerce из CSV файла
 * 
 * Использование:
 * wp taxonomy-import path/to/file.csv --mode=update|replace [--dry-run] [--verbose] [--delimiter=,] [--skip-lines=0] [--batch-size=100]
 */

if (!defined('WP_CLI') || !WP_CLI) {
    exit('Этот скрипт может быть запущен только через WP-CLI');
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
    private $mode = 'update'; // update или replace
    private $delimiter = ',';
    private $skip_lines = 0;
    private $batch_size = 100;
    private $max_file_size = 50 * 1024 * 1024; // 50MB
    
    /** Импорт таксономий из CSV файла
     *
     * ## OPTIONS
     *
     * <file>
     * : Путь к CSV файлу
     *
     * --mode=<mode>
     * : Режим работы: update (добавлять к существующим) или replace (заменять все)
     * ---
     * default: update
     * options:
     *   - update
     *   - replace
     * ---
     *
     * [--dry-run]
     * : Тестовый запуск без внесения изменений
     *
     * [--verbose]
     * : Подробный вывод
     *
     * [--delimiter=<delimiter>]
     * : Разделитель CSV
     * ---
     * default: ,
     * ---
     *
     * [--skip-lines=<lines>]
     * : Количество строк для пропуска в начале файла
     * ---
     * default: 0
     * ---
     *
     * [--batch-size=<size>]
     * : Количество строк для обработки за раз
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
        
        // БЛОК 1: Инициализация и общие проверки
        if (!$this->block1_initialization($args, $assoc_args)) {
            return;
        }
        
        // БЛОК 2: Валидация CSV файла
        if (!$this->block2_csv_validation($args[0])) {
            return;
        }
        
        // БЛОК 3: Подготовка массива данных
        $prepared_data = $this->block3_data_preparation($args[0]);
        if ($prepared_data === false) {
            return;
        }
        
        // БЛОК 4: Внесение изменений в БД
        if (!$this->dry_run) {
            if (!$this->block4_database_operations($prepared_data)) {
                return;
            }
        } else {
            WP_CLI::log("DRY RUN MODE: Изменения не вносятся");
        }
        
        // БЛОК 5: Завершение и отчетность
        $this->block5_finalization($args[0]);
    }
    
    /** БЛОК 1: Инициализация и общие проверки
     * 
     */
    private function block1_initialization($args, $assoc_args) {
        // Проверка параметров
        if (empty($args[0])) {
            WP_CLI::error('Необходимо указать путь к CSV файлу');
            return false;
        }
        
        // Установка параметров
        $this->mode = isset($assoc_args['mode']) ? $assoc_args['mode'] : 'update';
        $this->dry_run = isset($assoc_args['dry-run']);
        $this->verbose = isset($assoc_args['verbose']);
        $this->delimiter = isset($assoc_args['delimiter']) ? $assoc_args['delimiter'] : ',';
        $this->skip_lines = isset($assoc_args['skip-lines']) ? intval($assoc_args['skip-lines']) : 0;
        $this->batch_size = isset($assoc_args['batch-size']) ? intval($assoc_args['batch-size']) : 100;
        
        // Валидация параметров
        if (!in_array($this->mode, ['update', 'replace'])) {
            WP_CLI::error('Режим должен быть update или replace');
            return false;
        }
        
        if ($this->batch_size < 1 || $this->batch_size > 1000) {
            WP_CLI::error('Размер batch должен быть от 1 до 1000');
            return false;
        }
        
        // Проверка WooCommerce
        if (!class_exists('WooCommerce')) {
            WP_CLI::error('WooCommerce не найден');
            return false;
        }
        
        // Создание файла логов
        $log_dir = WP_CONTENT_DIR . '/uploads/taxonomy-import-logs/';
        if (!is_dir($log_dir)) {
            if (!wp_mkdir_p($log_dir)) {
                WP_CLI::error('Не удалось создать директорию для логов: ' . $log_dir);
                return false;
            }
        }
        
        $this->log_file = $log_dir . 'taxonomy_update_results_' . date('Y-m-d_H-i-s') . '.log';
        if (!is_writable($log_dir)) {
            WP_CLI::error('Нет прав для записи в директорию логов: ' . $log_dir);
            return false;
        }
        
        if ($this->verbose) {
            WP_CLI::log('Инициализация завершена');
            WP_CLI::log('Режим: ' . $this->mode);
            WP_CLI::log('Файл логов: ' . $this->log_file);
        }
        
        return true;
    }
    
    /** БЛОК 2: Валидация CSV файла
     *
     */
    private function block2_csv_validation($file_path) {
        // Проверка существования файла
        if (!file_exists($file_path)) {
            WP_CLI::error('Файл не найден: ' . $file_path);
            return false;
        }
        
        // Проверка размера файла
        $file_size = filesize($file_path);
        if ($file_size > $this->max_file_size) {
            WP_CLI::error('Файл слишком большой: ' . round($file_size / 1024 / 1024, 2) . 'MB. Максимум: ' . round($this->max_file_size / 1024 / 1024, 2) . 'MB');
            return false;
        }
        
        // Проверка кодировки
        $file_content = file_get_contents($file_path, false, null, 0, 1024);
        if (!mb_check_encoding($file_content, 'UTF-8')) {
            WP_CLI::warning('Файл может быть не в UTF-8 кодировке');
        }
        
        // Базовая проверка структуры CSV
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            WP_CLI::error('Не удалось открыть файл для чтения');
            return false;
        }
        
        // Пропуск строк если нужно
        for ($i = 0; $i < $this->skip_lines; $i++) {
            fgetcsv($handle, 0, $this->delimiter);
        }
        
        // Проверка заголовков
        $headers = fgetcsv($handle, 0, $this->delimiter);
        if (!$headers || empty($headers[0])) {
            WP_CLI::error('Не удалось прочитать заголовки CSV или первая колонка пуста');
            fclose($handle);
            return false;
        }
        
        // Первая колонка должна быть SKU
        $headers[0] = trim($headers[0]);
        if (strtolower($headers[0]) !== 'sku') {
            WP_CLI::error('Первая колонка должна быть SKU, найдено: ' . $headers[0]);
            fclose($handle);
            return false;
        }
        
        // Проверка что есть колонки с таксономиями
        if (count($headers) < 2) {
            WP_CLI::error('CSV должен содержать минимум 2 колонки (SKU + таксономии)');
            fclose($handle);
            return false;
        }
        
        fclose($handle);
        
        if ($this->verbose) {
            WP_CLI::log('Валидация CSV завершена');
            WP_CLI::log('Найдено колонок: ' . count($headers));
            WP_CLI::log('Таксономии: ' . implode(', ', array_slice($headers, 1)));
        }
        
        return true;
    }
    
    /** БЛОК 3: Подготовка массива данных
     *
     */
    private function block3_data_preparation($file_path) {
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            WP_CLI::error('Не удалось открыть файл для чтения');
            return false;
        }
        
        // Пропуск строк если нужно
        for ($i = 0; $i < $this->skip_lines; $i++) {
            fgetcsv($handle, 0, $this->delimiter);
        }
        
        // Чтение заголовков
        $headers = fgetcsv($handle, 0, $this->delimiter);
        $headers = array_map('trim', $headers);
        $taxonomy_columns = array_slice($headers, 1);
        
        // Проверка существования таксономий
        foreach ($taxonomy_columns as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                WP_CLI::error('Таксономия не существует: ' . $taxonomy);
                fclose($handle);
                return false;
            }
        }
        
        $prepared_data = [];
        $sku_duplicates = [];
        $line_number = $this->skip_lines + 2; // +1 для заголовков, +1 для нумерации с 1
        
        // Создание progress bar
        $total_lines = count(file($file_path)) - $this->skip_lines - 1;
        $progress = \WP_CLI\Utils\make_progress_bar('Подготовка данных', $total_lines);
        
        while (($row = fgetcsv($handle, 0, $this->delimiter)) !== false) {
            $this->stats['total_rows']++;
            
            // Обработка пустых строк
            if (empty(array_filter($row))) {
                $line_number++;
                $progress->tick();
                continue;
            }
            
            // Проверка количества колонок
            if (count($row) !== count($headers)) {
                WP_CLI::warning("Строка $line_number: неверное количество колонок (" . count($row) . " вместо " . count($headers) . ")");
            }
            
            $sku = trim($row[0]);
            if (empty($sku)) {
                WP_CLI::warning("Строка $line_number: пустой SKU");
                $line_number++;
                $progress->tick();
                continue;
            }
            
            // Проверка дубликатов SKU
            if (isset($prepared_data[$sku])) {
                $sku_duplicates[] = $sku;
            }
            
            // Поиск товара по SKU
            $product_id = wc_get_product_id_by_sku($sku);
            if (!$product_id) {
                $this->errors['products_not_found'][] = $sku;
                $line_number++;
                $progress->tick();
                continue;
            }
            
            $this->stats['products_found']++;
            
            // Подготовка данных таксономий
            $taxonomies_data = [];
            for ($i = 1; $i < count($headers) && $i < count($row); $i++) {
                $taxonomy = $taxonomy_columns[$i - 1];
                $term_value = trim($row[$i]);
                
                if (empty($term_value)) {
                    continue;
                }
                
                // Проверка существования термина
                $term = get_term_by('name', $term_value, $taxonomy);
                if (!$term) {
                    $this->errors['terms_not_found'][] = $taxonomy . ': "' . $term_value . '"';
                    continue;
                }
                
                // Проверка на дубликаты терминов
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
                
                // Проверка существующих связей (только для режима update)
                if ($this->mode === 'update') {
                    $existing_terms = wp_get_post_terms($product_id, $taxonomy, ['fields' => 'ids']);
                    if (in_array($term->term_id, $existing_terms)) {
                        $this->errors['already_existing'][] = $sku . ': ' . $taxonomy . ' "' . $term_value . '"';
                        $this->stats['taxonomies_skipped']++;
                        continue;
                    }
                }
                
                $taxonomies_data[$taxonomy][] = $term->term_id;
                
                // Статистика по таксономиям
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
        
        // Обработка дубликатов SKU
        if (!empty($sku_duplicates)) {
            WP_CLI::warning('Найдены дубликаты SKU: ' . implode(', ', array_unique($sku_duplicates)));
        }
        
        if ($this->verbose) {
            WP_CLI::log('Подготовка данных завершена');
            WP_CLI::log('Товаров для обработки: ' . count($prepared_data));
        }
        
        return $prepared_data;
    }
    
    /** БЛОК 4: Внесение изменений в БД
     * 
     */
    private function block4_database_operations($prepared_data) {
        if (empty($prepared_data)) {
            WP_CLI::warning('Нет данных для обработки');
            return true;
        }
        
        $progress = \WP_CLI\Utils\make_progress_bar('Обновление товаров', count($prepared_data));
        
        foreach ($prepared_data as $sku => $data) {
            $product_id = $data['product_id'];
            $taxonomies = $data['taxonomies'];
            
            try {
                foreach ($taxonomies as $taxonomy => $term_ids) {
                    if ($this->mode === 'replace') {
                        // Заменяем все термины
                        $result = wp_set_object_terms($product_id, $term_ids, $taxonomy, false);
                    } else {
                        // Добавляем к существующим
                        $result = wp_set_object_terms($product_id, $term_ids, $taxonomy, true);
                    }
                    
                    if (is_wp_error($result)) {
                        WP_CLI::error('Ошибка БД для товара ' . $sku . ': ' . $result->get_error_message());
                        return false;
                    }
                    
                    $this->stats['taxonomies_updated'] += count($term_ids);
                }
                
                // Очистка кеша товара
                clean_post_cache($product_id);
                
            } catch (Exception $e) {
                WP_CLI::error('Критическая ошибка БД для товара ' . $sku . ': ' . $e->getMessage());
                return false;
            }
            
            $progress->tick();
        }
        
        $progress->finish();
        
        if ($this->verbose) {
            WP_CLI::log('Обновление БД завершено');
        }
        
        return true;
    }
    
    /** БЛОК 5: Завершение и отчетность
     *
     */
    private function block5_finalization($csv_file) {
        $execution_time = microtime(true) - $this->start_time;
        $this->stats['products_not_found'] = count($this->errors['products_not_found']);
        
        // Создание отчета
        $report = $this->generate_report($csv_file, $execution_time);
        
        // Запись в файл логов
        file_put_contents($this->log_file, $report);
        
        // Вывод в терминал
        WP_CLI::log('');
        WP_CLI::log('=== РЕЗУЛЬТАТЫ ИМПОРТА ===');
        WP_CLI::log('Всего строк обработано: ' . $this->stats['total_rows']);
        WP_CLI::log('Товаров найдено: ' . $this->stats['products_found']);
        WP_CLI::log('Товаров не найдено: ' . $this->stats['products_not_found']);
        WP_CLI::log('Таксономий обновлено: ' . $this->stats['taxonomies_updated']);
        WP_CLI::log('Таксономий пропущено: ' . $this->stats['taxonomies_skipped']);
        WP_CLI::log('Время выполнения: ' . round($execution_time, 2) . ' сек');
        WP_CLI::log('');
        
        if (!empty($this->errors['products_not_found'])) {
            WP_CLI::warning('Не найдено товаров: ' . count($this->errors['products_not_found']));
        }
        
        if (!empty($this->errors['terms_not_found'])) {
            WP_CLI::warning('Не найдено терминов: ' . count($this->errors['terms_not_found']));
        }
        
        if (!empty($this->errors['duplicate_terms'])) {
            WP_CLI::warning('Найдено дубликатов терминов: ' . count($this->errors['duplicate_terms']));
        }
        
        WP_CLI::success('Детальный отчет сохранен: ' . $this->log_file);
        
        // Очистка кеша WordPress
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        // Обновление счетчиков терминов
        foreach ($this->stats['terms_per_taxonomy'] as $taxonomy => $count) {
            wp_update_term_count_now([], $taxonomy);
        }
    }
    
    /** Генерация детального отчета
     *
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

// Регистрация команды
WP_CLI::add_command('taxonomy-import', 'WC_Taxonomy_Import_Command');