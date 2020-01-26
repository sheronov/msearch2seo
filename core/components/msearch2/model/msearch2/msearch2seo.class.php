<?php

require_once 'msearch2.class.php';

/**
 * The base class for mSearch2.
 *
 * @package msearch2
 */
class mSearch2Seo extends mSearch2
{

    const URL_CLASS = 'sfUrls';
    protected $seoConfig = [];

    public function __construct(modX &$modx, array $config = [])
    {
        parent::__construct($modx, $config);
        $this->config['showSearchLog'] = true;
    }

    /**
     * Добавление SEO полей для выборки
     *
     * @param  array  $config
     *
     * @return array|void
     */
    public function getWorkFields($config = [])
    {
        parent::getWorkFields($config);
        $config = array_merge($this->config, $config);
        $seoFields = [];
        $seoFieldsSettings = $this->modx->getOption('mse2_seo_index_fields', null,
            'seo_word:5,seo_name:3,rule_title:1', true);

        $tmp = array_map('trim', explode(',', strtolower($seoFieldsSettings)));
        foreach ($tmp as $v) {
            $tmp2 = explode(':', $v);
            $seoFields[$tmp2[0]] = $tmp2[1] ?? 1;
        }

        if (!($config['fields'] ?? [])) {
            $this->fields = array_merge($this->fields, $seoFields);
        }
    }

    /**
     * Переопределён только новый метод поиска
     *
     * @param $query
     * @param  bool  $process_aliases
     *
     * @return array|mixed
     */
    public function Search($query, $process_aliases = true)
    {
        if (!empty($this->config['old_search_algorithm'])) {
            /** @noinspection PhpDeprecationInspection */
            return $this->OldSearch($query);
        }

        if ($process_aliases) {
            $query = preg_replace('/[^_-а-яёa-z0-9\s\.\/]+/iu', ' ', $this->modx->stripTags($query));
            $this->log('Filtered search query: "'.mb_strtolower($query).'"');
            if ($aliases = $this->getAliases($query)) {
                $this->log('Generated aliases for search query: "'.implode('; ', $aliases).'"');
                $results = [];
                foreach ($aliases as $aliasQuery) {
                    $this->log('Search by alias: "'.$aliasQuery.'"');
                    $results[] = $this->Search($aliasQuery, false);
                }
                $found = [];
                foreach ($results as $result) {
                    foreach ($result as $k => $v) {
                        if (isset($found[$k])) {
                            $found[$k] += $v;
                        } else {
                            $found[$k] = $v;
                        }
                    }
                }
                arsort($found);

                return $found;
            }
        }

        $search_forms = $this->getBaseForms($query, false);
        $bulk_words = $this->getBulkWords($query);

        // Search by words index
        $index = $debug = [];
        if (!empty($search_forms)) {
            $q = $this->modx->newQuery('mseWord');
            $q->select($this->modx->getSelectColumns('mseWord', 'mseWord'));
            $q->where(['word:IN' => array_keys($search_forms), 'field:IN' => array_keys($this->fields)]);
            $tstart = microtime(true);
            if ($q->prepare() && $q->stmt->execute()) {
                $this->modx->queryTime += microtime(true) - $tstart;
                $this->modx->executedQueries++;
                while ($row = $q->stmt->fetch(PDO::FETCH_ASSOC)) {
                    if ($row['class_key'] === self::URL_CLASS) {
                        $wordIndex = $row['resource'].'::'.$row['class_key'];
                    } else {
                        $wordIndex = $row['resource'];
                    }
                    $weight = $this->fields[$row['field']] * $row['count'];
                    // Add to results
                    if (!isset($index[$wordIndex])) {
                        $index[$wordIndex] = [
                            'words'  => [
                                $row['word'] => $weight,
                            ],
                            'weight' => $weight,
                        ];
                    } else {
                        if (!isset($index[$wordIndex][$row['word']])) {
                            $index[$wordIndex]['words'][$row['word']] = $weight;
                        } else {
                            $index[$wordIndex]['words'][$row['word']] += $weight;
                        }
                        $index[$wordIndex]['weight'] += $weight;
                    }

                    // Search by INDEX debug
                    $debug[$wordIndex][] = [
                        'field'  => $row['field'],
                        'word'   => $row['word'],
                        'count'  => $row['count'],
                        'weight' => $this->fields[$row['field']],
                        'total'  => $weight,
                    ];
                }
            }
        }

        $message = '';
        $leaved = $removed = 0;

        $result = [];
        if (!empty($index)) {
            $this->log('Found results by words INDEX ('.implode(',', $bulk_words).'): <b>'.count($index).'</b>');
            $all_forms = $this->getAllForms($bulk_words);
            foreach ($index as $k => $v) {
                $not_found = [];
                foreach ($bulk_words as $word) {
                    if (!isset($all_forms[$word])) {
                        $not_found[] = $word;
                    } elseif (!array_intersect($all_forms[$word], array_keys($v['words']))) {
                        $not_found[] = $word;
                    }
                }
                if (!empty($not_found)) {
                    $resourceId = explode('::', $k)[0];
                    if ($this->simpleSearch(implode(' ', $not_found), false, $resourceId)) {
                        foreach ($not_found as $word) {
                            $index[$k]['words'][$word] = $this->config['like_match_bonus'];
                            $index[$k]['weight'] += $this->config['like_match_bonus'];
                            $message .= "\n\t+ {$this->config['like_match_bonus']} points to resource {$k} for word \"{$word}\" in LIKE search";
                        }
                    } else {
                        unset($index[$k]);
                        $message .= "\n\t- {$k} because it does not contain all necessary words";
                        $removed++;
                        continue;
                    }
                }
                foreach ($debug[$k] as $v2) {
                    $message .= "\n\t+ {$v2['total']} points to resource {$k} for word \"{$v2['word']}\" in field \"{$v2['field']}\" ({$v2['count']} * {$v2['weight']})";
                }
                $result[$k] = $index[$k]['weight'];
                $leaved++;
            }
            $this->log("Filtering results of INDEX search: <b>{$leaved}</b> leaved, <b>{$removed}</b> removed.".$message);
        } else {
            $this->log('Nothing found by words INDEX');
        }

        if (empty($this->config['onlyIndex'])) {
            $added = 0;
            $like = $this->simpleSearch($query, false);
            $message = '';
            foreach ($like as $k) {
                if (!isset($result[$k])) {
                    $result[$k] = $this->config['like_match_bonus'];
                    $message .= "\n\t+ {$this->config['like_match_bonus']} points to resource {$k} for words \"".implode(',',
                            $bulk_words)."\"";
                    $added++;
                }
            }
            if ($added) {
                $this->log("Added results by LIKE search: <b>{$added}</b>".$message);
            }

            if (count($bulk_words) > 1) {
                $resourcesId = array_map(function ($key) {
                    return explode('::', $key)[0];
                }, array_keys($result));

                if ($exact = $this->simpleSearch($query, true, $resourcesId)) {
                    $message = 'Trying to apply "exact_match_bonus":';
                    foreach ($exact as $k) {
                        $result[$k] += $this->config['exact_match_bonus'];
                        $message .= "\n\t+ {$this->config['exact_match_bonus']} to resource {$k}";
                    }
                    $this->log($message);
                }
            }
        }

        // Sort results by weight
        arsort($result);

        // Log the search query
        $query = preg_replace('#[^\w\s-]#iu', '', $query);
        /** @var mseQuery $object */
        if ($object = $this->modx->getObject('mseQuery', ['query' => $query])) {
            $object->set('quantity', $object->get('quantity') + 1);
        } else {
            $object = $this->modx->newObject('mseQuery');
            $object->set('query', $query);
            $object->set('quantity', 1);
        }
        $object->set('found', count($result));
        $object->save();

        return $result;
    }

    public function simpleSearch($query, $exact = true, $resources = [])
    {
        $string = $this->modx->stripTags($query);

        $result = [];
        $q = $this->modx->newQuery('mseIntro');
        $q->select('resource,class_key');
        if ($exact && $string) {
            $q->where(['intro:LIKE' => '%'.$string.'%']);
        } elseif ($bulk_words = $this->getBulkWords($string, $this->config['split_all'])) {
            foreach ($bulk_words as $word) {
                $q->andCondition("intro LIKE '%{$word}%'");
            }
        } else {
            return $result;
        }
        if (!empty($resources)) {
            if (!is_array($resources)) {
                $q->where(['resource' => $resources]);
            } else {
                $q->where(['resource:IN' => $resources]);
            }
        }
        $tstart = microtime(true);
        if ($q->prepare() && $q->stmt->execute()) {
            $this->modx->queryTime += microtime(true) - $tstart;
            $this->modx->executedQueries++;
            while ($row = $q->stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($row['class_key'] === self::URL_CLASS) {
                    $result[] = $row['resource'].'::'.$row['class_key'];
                } else {
                    $result[] = $row['resource'];
                }
            }
        }

        return $result;
    }

    public function makeUrl(string $seoUrl, string $pageUrl, string $pageId)
    {
        $config = $this->loadSeoConfig();

        foreach ($config['possibleSuffixes'] as $possibleSuffix) {
            if (substr($pageUrl, -strlen($possibleSuffix)) === $possibleSuffix) {
                $pageUrl = substr($pageUrl, 0, -strlen($possibleSuffix));
            }
        }

        if ($config['siteStart'] === $pageId) {
            if ($config['mainAlias']) {
                $q = $this->modx->newQuery('modResource', ['id' => $pageId]);
                $q->select('alias');
                $mainAlias = $this->modx->getValue($q->prepare());
                $url = $pageUrl.'/'.$mainAlias.$config['betweenUrls'].$seoUrl.$config['urlSuffix'];
            } else {
                $url = $pageUrl.'/'.$seoUrl.$config['urlSuffix'];
            }
        } else {
            $url = $pageUrl.$config['betweenUrls'].$seoUrl.$config['urlSuffix'];
        }

        return $url;
    }

    protected function loadSeoConfig()
    {
        if (empty($this->seoConfig)) {
            $this->seoConfig = [
                'urlSuffix'        => $this->modx->getOption('seofilter_url_suffix', null, '', true),
                'betweenUrls'      => $this->modx->getOption('seofilter_between_urls', null, '/', true),
                'mainAlias'        => $this->modx->getOption('seofilter_main_alias', null, 0),
                'siteStart'        => $this->modx->context->getOption('site_start', 1),
                'possibleSuffixes' => array_map('trim',
                    explode(',', $this->modx->getOption('seofitler_possible_suffixes', null, '/,.html,.php',
                        true)))
            ];
        }
        return $this->seoConfig;
    }

}
