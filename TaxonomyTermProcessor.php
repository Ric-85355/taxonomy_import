<?php
/**
 * Класс для обработки терминов таксономий
 * Поддерживает как простые термины, так и иерархические
 */
class TaxonomyTermProcessor {
    
    private $errors = [];
    private $verbose = false;
    
    public function __construct($verbose = false) {
        $this->verbose = $verbose;
    }
    
    /**
     * Основной метод для обработки термина
     * @param string $term_value Значение термина из CSV
     * @param string $taxonomy Название таксономии
     * @return WP_Term|false Объект термина или false при ошибке
     */
    public function process_term($term_value, $taxonomy) {
        if (empty($term_value)) {
            return false;
        }
        
        // Проверяем, есть ли разделитель иерархии
        if (strpos($term_value, ' > ') === false) {
            // Обычный термин
            return $this->find_single_term($term_value, $taxonomy);
        }
        
        // Иерархический термин
        return $this->process_hierarchical_term($term_value, $taxonomy);
    }
    
    /**
     * Обработка иерархических терминов
     * Поддерживает нотацию "родитель > ребенок > внук"
     */
    private function process_hierarchical_term($term_value, $taxonomy) {
        // Разбиваем на уровни
        $levels = array_map('trim', explode(' > ', $term_value));
        $parent_id = 0;
        $found_term = null;
        
        // Проходим по каждому уровню иерархии
        foreach ($levels as $level_index => $level_name) {
            $term = get_term_by('name', $level_name, $taxonomy);
            
            if (!$term) {
                // Термин не найден
                $this->add_error('terms_not_found', $taxonomy . ': "' . $level_name . '" в иерархии "' . $term_value . '"');
                return false;
            }
            
            // Проверяем, что термин находится на правильном уровне иерархии
            if ($term->parent != $parent_id) {
                // Ищем среди дочерних терминов с нужным родителем
                $child_terms = get_terms([
                    'taxonomy' => $taxonomy,
                    'name' => $level_name,
                    'parent' => $parent_id,
                    'hide_empty' => false
                ]);
                
                if (empty($child_terms)) {
                    $parent_name = $parent_id ? get_term($parent_id)->name : 'корень';
                    $this->add_error('hierarchy_mismatch', $taxonomy . ': термин "' . $level_name . '" не найден как дочерний для "' . $parent_name . '" в иерархии "' . $term_value . '"');
                    return false;
                }
                
                // Для иерархических терминов берем первый найденный (дубликатов быть не должно)
                $term = $child_terms[0];
            }
            
            $found_term = $term;
            $parent_id = $term->term_id;
        }
        
        return $found_term;
    }
    
    /**
     * Поиск одиночного термина с обработкой дубликатов
     */
    private function find_single_term($term_name, $taxonomy) {
        $term = get_term_by('name', $term_name, $taxonomy);
        
        if (!$term) {
            $this->add_error('terms_not_found', $taxonomy . ': "' . $term_name . '"');
            return false;
        }
        
        // Проверка на дубликаты
        $duplicate_terms = get_terms([
            'taxonomy' => $taxonomy,
            'name' => $term_name,
            'hide_empty' => false
        ]);
        
        if (count($duplicate_terms) > 1) {
            // Для дубликатов выводим ошибку с рекомендацией использовать иерархию
            $term_ids = array_map(function($t) { return $t->term_id; }, $duplicate_terms);
            $this->add_error('duplicate_terms', $taxonomy . ': "' . $term_name . '" (IDs: ' . implode(', ', $term_ids) . ') - используйте полный путь "родитель > потомок"');
            return false;
        }
        
        return $term;
    }
    
    /**
     * Добавление ошибки в массив
     */
    private function add_error($type, $message) {
        if (!isset($this->errors[$type])) {
            $this->errors[$type] = [];
        }
        $this->errors[$type][] = $message;
    }
    
    /**
     * Получение всех ошибок
     */
    public function get_errors() {
        return $this->errors;
    }
    
    /**
     * Очистка ошибок
     */
    public function clear_errors() {
        $this->errors = [];
    }
    
    /**
     * Проверка наличия ошибок
     */
    public function has_errors() {
        return !empty($this->errors);
    }
    
    /**
     * Получение ошибок определенного типа
     */
    public function get_errors_by_type($type) {
        return isset($this->errors[$type]) ? $this->errors[$type] : [];
    }
}