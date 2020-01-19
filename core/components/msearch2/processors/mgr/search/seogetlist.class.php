<?php

class mseIndexSeoGetListProcessor extends modObjectGetListProcessor
{
    public $objectType = 'modResource';
    public $classKey   = 'modResource';
    /** @var mSearch2 $mSearch2 */
    public    $mSearch2;
    protected $ids       = [];
    protected $resources = [];
    protected $seoIds    = [];
    protected $seoPages  = [];
    protected $toSortIds = [];


    /**
     * @return bool|null|string
     */
    public function beforeQuery()
    {
        return $this->loadClass();
    }


    /**
     * @return array
     */
    public function getData()
    {
        $data = [];
        $limit = intval($this->getProperty('limit'));
        $start = intval($this->getProperty('start'));

        if ($query = $this->mSearch2->getQuery($this->getProperty('query'))) {
            $minQuery = $this->modx->getOption('index_min_words_length', null, 3, true);
            if (preg_match('/^[0-9]{2,}$/', $query) || mb_strlen($query, 'UTF-8') >= $minQuery) {
                $this->ids = $this->mSearch2->Search($query);
            }
        }
        if (empty($this->ids)) {
            return ['total' => 0, 'results' => []];
        }

        foreach ($this->ids as $fKey => $foundWeight) {
            $tmp = explode('::', $fKey);
            if (isset($tmp[1]) && $tmp[1] === 'sfUrls') {
                $this->seoIds[] = $tmp[0];
            } else {
                $this->resources[] = $tmp[0];
            }
            $this->toSortIds[] = $tmp[0];
        }

        if (!empty($this->seoIds)) {
            $q = $this->modx->newQuery('sfUrls');
            $q->where(['id:IN' => array_unique($this->seoIds)]);
            $q->select('page_id');
            if ($q->prepare() && $q->stmt->execute()) {
                $this->seoPages = $q->stmt->fetchAll(PDO::FETCH_COLUMN);
            }
            $this->resources = array_unique(array_merge($this->resources, $this->seoPages));
        }

        /* query for chunks */
        $c = $this->modx->newQuery($this->classKey);
        $c = $this->prepareQueryBeforeCount($c);
        $data['total'] = $this->modx->getCount($this->classKey, $c);
        $c = $this->prepareQueryAfterCount($c);


        if ($limit > 0) {
            $c->limit($limit, $start);
        }

        $c->select([
            $this->modx->getSelectColumns($this->classKey, $this->classKey),
            $this->modx->getSelectColumns('mseIntro', 'mseIntro', '', ['intro']),
        ]);
        if (!empty($this->seoIds)) {
            $c->select([
                '`IntroSeo`.`intro` as `introseo`',
                $this->modx->getSelectColumns('sfUrls', 'sfUrls', 'seo_')
            ]);
            $c->groupby($this->classKey.'.id, sfUrls.id');
            $c->sortby('FIELD(IFNULL(sfUrls.id, modResource.id), '.implode(',', $this->toSortIds).')');
        } else {
            $c->sortby('find_in_set(`id`,\''.implode(',', array_keys($this->ids)).'\')', '');
        }

        if ($c->prepare() && $c->stmt->execute()) {
            $data['results'] = $c->stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $data;
    }


    /**
     * @param  array  $data
     *
     * @return array
     */
    public function iterate(array $data)
    {
        $list = [];
        foreach ($data['results'] as $array) {
            $objectArray = $this->prepareArray($array);
            if (!empty($objectArray) && is_array($objectArray)) {
                $list[] = $objectArray;
            }
        }

        return $list;
    }


    /**
     * @param  xPDOQuery  $c
     *
     * @return xPDOQuery
     */
    public function prepareQueryBeforeCount(xPDOQuery $c)
    {
        $c->where(['id:IN' => $this->resources]);
        if (!empty($this->seoIds)) {
            $c->leftJoin('sfUrls', 'sfUrls', $this->classKey.'.id = sfUrls.page_id AND modResource.id IN ('.implode(',',
                    $this->seoPages).') AND sfUrls.id IN ('.implode(',', $this->seoIds).')');
            $c->leftJoin('mseIntro', 'IntroSeo', "sfUrls.id = IntroSeo.resource  AND IntroSeo.class_key = 'sfUrls'");
        }

        $c->leftJoin('mseIntro', 'mseIntro',
            "`modResource`.`id` = `mseIntro`.`resource` AND `mseIntro`.`class_key` != 'sfUrls'");

        if (!$this->getProperty('unpublished')) {
            $c->where(['published' => 1]);
        }
        if (!$this->getProperty('deleted')) {
            $c->where(['deleted' => 0]);
        }

        return $c;
    }


    /**
     * @param  array  $array
     *
     * @return array
     */
    public function prepareArray(array $array)
    {
        if (isset($array['seo_id'])) {
            $array['weight'] = $this->ids[$array['seo_id'].'::'.'sfUrls'] ?? '';
            $seoUrl = $array['seo_new_url'] ?: $array['seo_old_url'];
            $array['uri'] = $this->mSearch2->makeUrl($seoUrl, $array['uri'], $array['id']);
        } else {
            $array['weight'] = $this->ids[$array['id']] ?? '';
        }

        if ($array['introseo'] ?? false) {
            $array['intro'] = $this->mSearch2->Highlight($array['introseo'], $this->getProperty('query'), '<b>',
                '</b>');
        } else {
            $array['intro'] = $this->mSearch2->Highlight($array['intro'], $this->getProperty('query'), '<b>', '</b>');
        }
        return $array;
    }


    /**
     * @return bool
     */
    public function loadClass()
    {
        if ($this->modx->loadClass('msearch2seo', MODX_CORE_PATH.'components/msearch2/model/msearch2/', false, true)) {
            $this->mSearch2 = new mSearch2Seo($this->modx, []);
        }

        $this->modx->addPackage('seofilter', $this->modx->getOption('seofilter_core_path', [],
                $this->modx->getOption('core_path').'components/seofilter/').'model/');

        return $this->mSearch2 instanceof mSearch2Seo;
    }

}

return 'mseIndexSeoGetListProcessor';