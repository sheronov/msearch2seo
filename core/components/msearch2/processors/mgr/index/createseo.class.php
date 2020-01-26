<?php

/**
 * Create search index of all resources
 *
 * @package msearch2
 * @subpackage processors
 * @property mSearch2Seo mSearch2
 */

require_once 'create.class.php';

class mseIndexCreateSeoProcessor extends mseIndexCreateProcessor
{

    protected $indexSeoEmpty      = false;
    protected $processedRealPages = [];
    protected $seoPagesToIndex  = [];
    protected $resourceFields     = [];
    protected $seoFields          = [];
    protected $ruleFields         = [];

    /**
     * {@inheritDoc}
     */
    public function process()
    {
        $this->loadClass();
        $this->mSearch2->getWorkFields();

        $collection = $this->getResources();
        if (!is_array($collection) && empty($collection)) {
            return $this->failure('mse2_err_no_resources_for_index');
        }

        $process_comments = $this->modx->getOption('mse2_index_comments', null, true, true)
            && class_exists('Ticket');
        $i = 0;
        /* @var modResource|Ticket|msProduct $resource */
        foreach ($collection as $data) {
            if ($data['deleted'] || !$data['searchable']) {
                $this->unIndex($data['id'], false);
                if (!empty($data['seo_id'])) {
                    $this->unIndex($data['seo_id'], true);
                }
                continue;
            }

            if (!empty($data['seo_id'])) {
                //значит SEO страница
                if (empty($data['seo_active'])) {
                    //неактивные неиндексим
                    $this->unIndex($data['seo_id'], true);
                } else {
                    $this->seoPagesToIndex[$data['seo_id']] = $data;
                    $i++;
                }

                foreach ($data as $key => $val) {
                    if (mb_strpos($key, 'seo_') === 0 || mb_strpos($key, 'rule_') === 0) {
                        unset($data[$key]);
                    }
                }
            }

            if (isset($this->processedRealPages[$data['id']])) {
                continue;
            }

            $class_key = $data['class_key'];
            if (!isset($this->modx->map[$class_key])) {
                continue;
            }
            $resource = $this->modx->newObject($class_key);
            $resource->fromArray($data, '', true, true);

            $comments = '';
            if ($process_comments) {
                $q = $this->modx->newQuery('TicketComment', ['deleted' => 0, 'published' => 1]);
                $q->innerJoin('TicketThread', 'Thread',
                    '`TicketComment`.`thread`=`Thread`.`id` AND `Thread`.`deleted`=0');
                $q->innerJoin('modResource', 'Resource',
                    '`Thread`.`resource`=`Resource`.`id` AND `Resource`.`id`='.$resource->get('id'));
                $q->select('text');
                if ($q->prepare() && $q->stmt->execute()) {
                    while ($row = $q->stmt->fetch(PDO::FETCH_COLUMN)) {
                        $comments .= $row.' ';
                    }
                }
            }
            $resource->set('comment', $comments);

            $this->Index($resource);
            $this->processedRealPages[$data['id']] = true;
            $i++;
        }

        if (!empty($this->seoPagesToIndex)) {
            $this->createIndexForSeoPages();
        }

        $offset = $this->_offset + $this->_limit;
        $done = $offset >= $this->_total;

        return $this->success('', [
            'indexed' => $i,
            'offset'  => $done ? 0 : $offset,
            'done'    => $done,
        ]);
    }

    protected function createIndexForSeoPages()
    {
        //TODO: здесь всё нужно синдексировать
    }

    /**
     * Prepares query and returns resource for indexing
     *
     * @return array|null
     */
    public function getResources()
    {
        $this->_limit = $this->getProperty('limit', 100);
        $this->_offset = $this->getProperty('offset', 0);

        $c = $this->modx->newQuery('modResource');
        if ($this->indexSeoEmpty) {
            $c->leftJoin('sfUrls', 'sfUrls', 'modResource.id = sfUrls.page_id');
        } else {
            $c->leftJoin('sfUrls', 'sfUrls', 'modResource.id = sfUrls.page_id AND sfUrls.total > 0');
        }
        $c->leftJoin('sfRule', 'sfRule', 'sfRule.id = sfUrls.multi_id');
        $c->select($this->modx->getSelectColumns('modResource', 'modResource', '', $this->resourceFields));
        $c->select($this->modx->getSelectColumns('sfUrls', 'sfUrls', 'seo_', $this->seoFields));
        $c->select($this->modx->getSelectColumns('sfRule', 'sfRule', 'rule_', $this->ruleFields));
        $c->groupby('modResource.id, sfUrls.id');
        $c->sortby('modResource.id', 'ASC');
        $c->sortby('sfUrls.id', 'ASC');
        $c = $this->prepareQuery($c);
        $this->_total = $this->modx->getCount('modResource', $c);
        $c->limit($this->_limit, $this->_offset);
        $collection = [];
        if ($c->prepare() && $c->stmt->execute()) {
            $collection = $c->stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $this->modx->log(modX::LOG_LEVEL_ERROR,
                '[mSearch2] Could not retrieve collection of resources: '.$c->stmt->errorInfo());
        }

        return $collection;
    }

    /**
     * Create index of resource
     *
     * @param  xPDOObject  $resource
     */
    public function Index(xPDOObject $resource)
    {
        $this->modx->invokeEvent('mse2OnBeforeSearchIndex', [
            'object'   => $resource,
            'resource' => $resource,
            'mSearch2' => $this->mSearch2,
        ]);

        $words = $intro = [];
        // For proper transliterate umlauts
        setlocale(LC_ALL, 'en_US.UTF8', LC_CTYPE);

        foreach ($this->mSearch2->fields as $field => $weight) {
            if (strpos($field, 'tv_') !== false && $resource instanceof modResource) {
                $text = $resource->getTVValue(substr($field, 3));
                // Support for arrays in TVs
                if (!empty($text) && ($text[0] === '[' || $text[0] === '{')) {
                    $tmp = $this->modx->fromJSON($text);
                    if (is_array($tmp)) {
                        $text = $tmp;
                    }
                }
            } else {
                $text = $resource->get($field);
            }
            if (is_array($text)) {
                $text = $this->_implode_r(' ', $text);
            }
            $text = $this->modx->stripTags($text);
            $forms = $this->_getBaseForms($text);
            $intro[] = $text;
            foreach ($forms as $form => $count) {
                $words[$form][$field] = $count;
            }
        }

        $tword = $this->modx->getTableName('mseWord');
        $tintro = $this->modx->getTableName('mseIntro');
        $resource_id = $resource->get('id');

        $intro = str_replace(["\n", "\r\n", "\r"], ' ', implode(' ', $intro));
        $intro = preg_replace('#\s+#u', ' ', str_replace(['\'', '"', '«', '»', '`'], '', $intro));
        $sql = "INSERT INTO {$tintro} (`resource`, `intro`) VALUES ('$resource_id', '$intro') ON DUPLICATE KEY UPDATE `intro` = '$intro';";
        $sql .= "DELETE FROM {$tword} WHERE `resource` = '$resource_id' AND `class_key` != 'sfUrls';";

        if (!$class_key = $resource->get('class_key')) {
            $class_key = get_class($resource);
        }
        if (!empty($words)) {
            $rows = [];
            foreach ($words as $word => $fields) {
                foreach ($fields as $field => $count) {
                    $rows[] = "({$resource_id}, '{$field}', '{$word}', '{$count}', '{$class_key}')";
                }
            }
            $sql .= "INSERT INTO {$tword} (`resource`, `field`, `word`, `count`, `class_key`) VALUES ".implode(',',
                    $rows);
        }

        $q = $this->modx->prepare($sql);
        if ($q->execute()) {
            $this->modx->invokeEvent('mse2OnSearchIndex', [
                'object'   => $resource,
                'resource' => $resource,
                'words'    => $words,
                'mSearch2' => $this->mSearch2,
            ]);
        } else {
            $this->modx->log(modX::LOG_LEVEL_ERROR,
                '[mSearch2] Could not save search index of resource '.$resource_id.': '.print_r($q->errorInfo(), 1));
        }
    }


    /**
     * Remove index of resource
     *
     * @param  integer  $resource_id
     * @param  bool  $seo
     */
    public function unIndex($resource_id, bool $seo = false)
    {
        $where = "WHERE `resource` = '$resource_id'";
        if ($seo) {
            $where .= " AND class_key = 'sfUrls'";
        } else {
            $where = " AND class_key != 'sfUrls'";
        }
        $sql = "DELETE FROM {$this->modx->getTableName('mseWord')} {$where};";
        $sql .= "DELETE FROM {$this->modx->getTableName('mseIntro')} {$where};";

        $this->modx->exec($sql);
    }


    /**
     * Loads mSearch2 class to processor
     *
     * @return bool
     */
    public function loadClass()
    {
        $this->indexSeoEmpty = (bool)$this->modx->getOption('mse2_seo_index_empty', [], 0);
        $this->modx->addPackage('seofilter', $this->modx->getOption('seofilter_core_path', [],
                $this->modx->getOption('core_path').'components/seofilter/').'model/');
        /** @noinspection PhpUndefinedFieldInspection */
        if (!empty($this->modx->mSearch2) && $this->modx->mSearch2 instanceof mSearch2Seo) {
            /** @noinspection PhpUndefinedFieldInspection */
            $this->mSearch2 = &$this->modx->mSearch2;
        } else {
            if (!class_exists('mSearch2Seo')) {
                /** @noinspection PhpIncludeInspection */
                require_once MODX_CORE_PATH.'components/msearch2/model/msearch2/msearch2seo.class.php';
            }
            $this->mSearch2 = new mSearch2Seo($this->modx, []);
        }
        $this->modx->sanitizePatterns['fenom'] = '#\{.*\}#si';

        $this->prepareClassesFields();

        return $this->mSearch2 instanceof mSearch2Seo;
    }

    protected function prepareClassesFields()
    {
        $resourceFields = [];
        $seoFields = [];
        $ruleFields = [];
        foreach (array_keys($this->mSearch2->fields) as $field) {
            if (mb_strpos($field, 'seo_') === 0) {
                $seoFields[] = str_replace('seo_', '', $field);
            } elseif (mb_strpos($field, 'rule_') === 0) {
                $ruleFields[] = str_replace('rule_', '', $field);
            } else {
                $resourceFields[] = $field;
            }
        }
        $this->resourceFields = array_unique(array_merge(
            array_intersect(array_keys($this->modx->getFieldMeta('modResource')), $resourceFields),
            ['id', 'class_key', 'deleted', 'searchable']
        ));
        $this->seoFields = array_unique(array_merge(
            array_intersect(array_keys($this->modx->getFieldMeta('sfUrls')), $seoFields),
            ['id', 'active', 'total']
        ));
        $this->ruleFields = array_unique(array_merge(
            array_intersect(array_keys($this->modx->getFieldMeta('sfRule')), $ruleFields),
            ['id', 'active']
        ));
    }

}

return 'mseIndexCreateSeoProcessor';
