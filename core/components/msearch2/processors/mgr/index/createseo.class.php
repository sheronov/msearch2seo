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
    protected $seoPagesToIndex    = [];
    protected $resourceFields     = [];
    protected $seoFields          = [];
    protected $ruleFields         = [];
    protected $seoFilterPro       = false;
    protected $loadedPages        = [];

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
        if ($this->seoFilterPro) {
            $this->loadLinkedPages();
        }
        $seoIds = array_keys($this->seoPagesToIndex);
        $q = $this->modx->newQuery('sfUrlWord')
            ->select('JSON_ARRAYAGG(url_id) as urls_id')
            ->where(['url_id:IN' => $seoIds])
            ->leftJoin('sfDictionary', 'sfDictionary', 'sfUrlWord.word_id = sfDictionary.id')
            ->leftJoin('sfField', 'sfField', 'sfDictionary.field_id = sfField.id')
            ->select($this->modx->getSelectColumns('sfDictionary', 'sfDictionary', ''))
            ->select('sfField.alias as field_alias')
            ->groupby('sfDictionary.id')
            ->sortby('sfDictionary.id');


        if ($q->prepare() && $q->stmt->execute()) {
            while ($row = $q->stmt->fetch(PDO::FETCH_ASSOC)) {
                $urlsId = $row['urls_id'];
                if (!is_array($urlsId)) {
                    $urlsId = $this->modx->fromJSON($urlsId);
                }
                foreach ($urlsId as $urlId) {
                    if (isset($this->seoPagesToIndex[$urlId]['_words'])) {
                        $this->seoPagesToIndex[$urlId]['_words'][$row['field_alias']] = $row;
                    } else {
                        $this->seoPagesToIndex[$urlId]['_words'] = [$row['field_alias'] => $row];
                    }
                }
            }
        }

        foreach ($this->seoPagesToIndex as $seoPageData) {
            $this->indexSeoPage($seoPageData);
        }
    }

    protected function loadLinkedPages()
    {
        $linkedPageIds = array_diff(array_unique(array_column($this->seoPagesToIndex, 'id')),
            array_keys($this->loadedPages));
        if (!empty($linkedPageIds)) {
            $pages = $this->modx->getCollection('modResource', ['id:IN' => $linkedPageIds]);
            foreach ($pages as $page) {
                if (!isset($this->loadedPages[$page->get('id')])) {
                    $this->loadedPages[$page->get('id')] = $page->toArray();
                }
            }
        }
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

    protected function indexSeoPage(array $data)
    {
        $words = $intro = [];
        // For proper transliterate umlauts
        setlocale(LC_ALL, 'en_US.UTF8', LC_CTYPE);

        foreach ($this->mSearch2->fields as $field => $weight) {
            if (mb_strpos($field, 'seo_') === false && mb_strpos($field, 'rule_') === false) {
                continue;
            }
            if ($field === 'seo_word') {
                $text = implode(' ', array_column($data['_words'], 'value'));
            } elseif ($text = $data[$field] ?? '') {
                $text = $this->mSearch2->pdoTools->getChunk('@INLINE '.$text, $this->prepareWordsForSeo($data));
            }
            $text = $this->modx->stripTags($text);
            if (!empty($text)) {
                $forms = $this->_getBaseForms($text);
                $intro[] = $text;
                foreach ($forms as $form => $count) {
                    $words[$form][$field] = $count;
                }
            }
        }


        $q = $this->toBdQuery($data['seo_id'], 'sfUrls', $intro, $words);
        if (!$q->execute()) {
            $this->modx->log(modX::LOG_LEVEL_ERROR,
                '[mSearch2] Could not save search index of SEO page '.$data['seo_id'].': '
                .print_r($q->errorInfo(), 1));
        }
    }

    protected function prepareWordsForSeo(array $data): array
    {
        $words = [
            'total'       => (int)$data['seo_total'],
            'count'       => (int)$data['seo_total'],
            'rule_id'     => (int)$data['rule_id'],
            'url'         => (int)$data['seo_id'],
            'link'        => $data['seo_link'],
            'page_number' => 1,
        ];

        foreach ($data['_words'] as $field => $word) {
            foreach ($word as $key => $val) {
                if (!isset($words[$key])) {
                    $words[$key] = $val;
                }
                if (mb_strpos($key, 'value') !== false) {
                    $words[str_replace('value', $field, $key)] = $val;
                }
            }
            $words[$field.'_input'] = $word['input'];
            $words[$field.'_alias'] = $word['alias'];
            $words[$field.'_word'] = $word['id'];
            $words['m_'.$field] = $word['m_value_i'];
        }

        foreach (['id', 'page', 'page_id'] as $pkey) {
            if (!isset($words[$pkey])) {
                $words[$pkey] = (int)$data['id'];
            }
        }

        if (isset($this->loadedPages[$data['id']])) {
            if (!isset($words['resource'])) {
                $words['resource'] = $this->loadedPages[$data['id']];
            }
            if (!isset($words['original_page'])) {
                $words['original_page'] = $this->loadedPages[$data['id']];
            }
        }


        return $words;
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

        if (!$classKey = $resource->get('class_key')) {
            $classKey = get_class($resource);
        }
        $q = $this->toBdQuery($resource->get('id'), $classKey, $intro, $words);
        if ($q->execute()) {
            $this->modx->invokeEvent('mse2OnSearchIndex', [
                'object'   => $resource,
                'resource' => $resource,
                'words'    => $words,
                'mSearch2' => $this->mSearch2,
            ]);
        } else {
            $this->modx->log(modX::LOG_LEVEL_ERROR,
                '[mSearch2] Could not save search index of resource '.$resource->get('id').': '
                .print_r($q->errorInfo(), 1));
        }
    }

    protected function toBdQuery(int $resourceId, string $classKey, array $intro, array $words, bool $seo = false)
    {
        $mseWordTable = $this->modx->getTableName('mseWord');
        $mseIntroTable = $this->modx->getTableName('mseIntro');

        $intro = str_replace(["\n", "\r\n", "\r"], ' ', implode(' ', $intro));
        $intro = preg_replace('#\s+#u', ' ', str_replace(['\'', '"', '«', '»', '`'], '', $intro));
        $sql = "INSERT INTO {$mseIntroTable} (`resource`, `intro`, `class_key`)"
            ." VALUES ('{$resourceId}', '{$intro}', '{$classKey}')"
            ." ON DUPLICATE KEY UPDATE `intro` = '{$intro}';";
        if ($seo) {
            $sql .= "DELETE FROM {$mseWordTable} WHERE `resource` = '{$resourceId}' AND `class_key` != 'sfUrls';";
        } else {
            $sql .= "DELETE FROM {$mseWordTable} WHERE `resource` = '{$resourceId}' AND `class_key` = 'sfUrls';";
        }

        if (!empty($words)) {
            $rows = [];
            foreach ($words as $word => $fields) {
                foreach ($fields as $field => $count) {
                    $rows[] = "({$resourceId}, '{$field}', '{$word}', '{$count}', '{$classKey}')";
                }
            }
            $sql .= "INSERT INTO {$mseWordTable} (`resource`, `field`, `word`, `count`, `class_key`) VALUES ".implode(',',
                    $rows);
        }

        return $this->modx->prepare($sql);
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
        $this->seoFilterPro = (bool)$this->modx->getOption('seofilter_pro_mode', null, 0, true);
        $this->modx->addPackage('seofilter', $this->modx->getOption('seofilter_core_path', [],
                $this->modx->getOption('core_path').'components/seofilter/').'model/');

        if (!class_exists('mSearch2Seo')) {
            require_once MODX_CORE_PATH.'components/msearch2/model/msearch2/msearch2seo.class.php';
        }
        if (!$this->mSearch2) {
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
                $seoField = str_replace('seo_', '', $field);
                $seoFields[] = $seoField;
                if (in_array($seoField, [
                    'title',
                    'h1',
                    'h2',
                    'description',
                    'introtext',
                    'keywords',
                    'text',
                    'content'
                ], true)) {
                    $ruleFields[] = $seoField;
                }
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
            ['id', 'active', 'total', 'custom']
        ));
        $this->ruleFields = array_unique(array_merge(
            array_intersect(array_keys($this->modx->getFieldMeta('sfRule')), $ruleFields),
            ['id', 'active', 'link_tpl']
        ));
    }

}

return 'mseIndexCreateSeoProcessor';
