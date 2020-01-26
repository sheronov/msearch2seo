<?php
/**
 * Returns stat of mSearch2 index
 *
 * @package msearch2
 * @subpackage processors
 */

class mseIndexStatSeoProcessor extends modProcessor
{

    public function __construct(modX &$modx, array $properties = [])
    {
        parent::__construct($modx, $properties);
        $this->modx->addPackage('seofilter', $this->modx->getOption('seofilter_core_path', [],
                $this->modx->getOption('core_path').'components/seofilter/').'model/');
    }

    public function process()
    {
        $array = [
            'total'             => $this->getTotal(),
            'total_seo'         => $this->getSeoTotal(),
            'total_seo_results' => $this->getSeoTotal(true),
            'indexed'           => $this->getIndexed(),
            'indexed_seo'       => $this->getIndexed(true),
            'words'             => $this->getWords(),
            'words_seo'         => $this->getWords(true),
        ];

        return $this->success('', $array);
    }


    public function getTotal()
    {
        $q = $this->modx->newQuery('modResource');
        $q->select('COUNT(`id`)');

        return ($q->prepare() && $q->stmt->execute()) ? $q->stmt->fetch(PDO::FETCH_COLUMN) : 0;
    }

    public function getSeoTotal(bool $withResults = false)
    {
        $q = $this->modx->newQuery('sfUrls');
        $q->select('COUNT(`id`)');
        if ($withResults) {
            $q->where('total > 0');
        }

        return ($q->prepare() && $q->stmt->execute()) ? $q->stmt->fetch(PDO::FETCH_COLUMN) : 0;
    }


    public function getIndexed(bool $seo = false)
    {
        $q = $this->modx->newQuery('mseIntro');
        $q->select('COUNT(`resource`)');
        if ($seo) {
            $q->where("class_key = 'sfUrls'");
        } else {
            $q->where("class_key != 'sfUrls'");
        }

        return ($q->prepare() && $q->stmt->execute()) ? $q->stmt->fetch(PDO::FETCH_COLUMN) : 0;
    }


    public function getWords(bool $seo = false)
    {
        $q = $this->modx->newQuery('mseWord');
        $q->select('COUNT(DISTINCT `word`)');
        if ($seo) {
            $q->where("class_key = 'sfUrls'");
        } else {
            $q->where("class_key != 'sfUrls'");
        }

        return ($q->prepare() && $q->stmt->execute()) ? $q->stmt->fetch(PDO::FETCH_COLUMN) : 0;
    }

}

return 'mseIndexStatSeoProcessor';