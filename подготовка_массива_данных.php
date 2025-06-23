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
    