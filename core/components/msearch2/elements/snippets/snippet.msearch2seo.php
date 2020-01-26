<?php
/** @var modX $modx */

/** @var array $scriptProperties */
/** @var mSearch2Seo $mSearch2 */
if (!$modx->loadClass('msearch2seo', MODX_CORE_PATH.'components/msearch2/model/msearch2/', false, true)) {
    return false;
}
$modx->addPackage('seofilter', $modx->getOption('seofilter_core_path', [],
        $modx->getOption('core_path').'components/seofilter/').'model/');
$seoHideEmpty = (bool)$modx->getOption('seofilter_hide_empty', [], 0);
$mSearch2 = new mSearch2Seo($modx, $scriptProperties);
$mSearch2->pdoTools->setConfig($scriptProperties);
$mSearch2->pdoTools->addTime('pdoTools loaded.');

if (empty($queryVar)) {
    $queryVar = 'query';
}
if (empty($parentsVar)) {
    $parentsVar = 'parents';
}
if (empty($minQuery)) {
    $minQuery = $modx->getOption('index_min_words_length', null, 3, true);
}
if (empty($htagOpen)) {
    $htagOpen = '<b>';
}
if (empty($htagClose)) {
    $htagClose = '</b>';
}
if (empty($outputSeparator)) {
    $outputSeparator = "\n";
}
if (empty($plPrefix)) {
    $plPrefix = 'mse2_';
}
$returnData = !empty($scriptProperties['returnData']);
$returnIds = !empty($returnIds);
$fastMode = !empty($fastMode);

$class = 'modResource';
$found = [];
$output = null;
$query = !empty($_REQUEST[$queryVar])
    ? $mSearch2->getQuery(rawurldecode($_REQUEST[$queryVar]))
    : '';

$seoIds = [];
$toSortIds = [];

if (empty($resources)) {
    if (empty($query) && isset($_REQUEST[$queryVar])) {
        $output = $modx->lexicon('mse2_err_no_query');
    } elseif (empty($query) && !empty($forceSearch)) {
        $output = $modx->lexicon('mse2_err_no_query_var');
    } elseif (!empty($query) && !preg_match('/^[0-9]{2,}$/', $query) && mb_strlen($query, 'UTF-8') < $minQuery) {
        $output = $modx->lexicon('mse2_err_min_query');
    }

    $modx->setPlaceholder($plPrefix.$queryVar, $query);

    if (!empty($output)) {
        return !$returnIds
            ? $output
            : '';
    }

    if (!empty($query)) {
        $found = $mSearch2->Search($query);

        if (empty($found)) {
            if ($returnIds) {
                return '';
            }

            $output = $modx->lexicon('mse2_err_no_results');

            if (!empty($tplWrapper) && !empty($wrapIfEmpty)) {
                $output = $mSearch2->pdoTools->getChunk(
                    $tplWrapper,
                    [
                        'output'  => $output,
                        'total'   => 0,
                        'query'   => $query,
                        'parents' => $modx->getPlaceholder($plPrefix.$parentsVar),
                    ],
                    $fastMode
                );
            }
            if ($modx->user->hasSessionContext('mgr') && !empty($showLog)) {
                $output .= '<pre class="mSearchLog">'.print_r($mSearch2->pdoTools->getTime(), 1).'</pre>';
            }
            if (!empty($toPlaceholder)) {
                $modx->setPlaceholder($toPlaceholder, $output);
                return;
            }

            return $output;
        }
    }
} elseif (strpos($resources, '{') === 0) {
    $found = $modx->fromJSON($resources);
}

if (!empty($found)) {
    $resources = [];
    foreach ($found as $fKey => $foundWeight) {
        $tmp = explode('::', $fKey);
        if (isset($tmp[1]) && $tmp[1] === 'sfUrls') {
            $seoIds[] = $tmp[0];
        } else {
            $resources[] = $tmp[0];
        }
        $toSortIds[] = $tmp[0];
    }
}

$seoPages = [];
$leftJoin = [];
if (!empty($seoIds)) {
    $q = $modx->newQuery('sfUrls');
    $q->where(['id:IN' => array_unique($seoIds)]);
    $q->select('page_id');
    if ($q->prepare() && $q->stmt->execute()) {
        $seoPages = $q->stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    if (empty($returnIds)) {
        $sfUrlsJoinCondition = $class.'.id = sfUrls.page_id AND modResource.id IN ('.implode(',',
                $seoPages).') AND sfUrls.id IN ('.implode(',', $seoIds).') AND sfUrls.active = 1';
        if ($seoHideEmpty) {
            $sfUrlsJoinCondition .= ' AND sfUrls.total > 0';
        }

        $leftJoin['sfUrls'] = [
            'class' => 'sfUrls',
            'alias' => 'sfUrls',
            'on'    => $sfUrlsJoinCondition
        ];

        $leftJoin['IntroSeo'] = [
            'class' => 'mseIntro',
            'alias' => 'IntroSeo',
            'on'    => "sfUrls.id = IntroSeo.resource  AND IntroSeo.class_key = 'sfUrls'"
        ];
    }
}

if (!empty($resources)) {
    if (!is_array($resources)) {
        $resources = array_map('trim', explode(',', $resources));
    }
    $resources = array_unique(array_merge($resources, $seoPages));
    unset($scriptProperties['resources']);
}

/*----------------------------------------------------------------------------------*/
if (empty($returnIds)) {
    // Joining tables
    $leftJoin['mseIntro'] = [
        'class' => 'mseIntro',
        'alias' => 'Intro',
        'on'    => $class.".id = Intro.resource  AND Intro.class_key != 'sfUrls'"
    ];

    // Fields to select
    $resourceColumns = !empty($includeContent)
        ? $modx->getSelectColumns($class, $class)
        : $modx->getSelectColumns($class, $class, '', ['content'], true);
    $select = [
        $class  => $resourceColumns,
        'Intro' => 'Intro.intro as intro',
    ];
    if (!empty($seoIds)) {
        $select['IntroSeo'] = 'IntroSeo.intro as introseo';
        $select['sfUrls'] = $modx->getSelectColumns('sfUrls', 'sfUrls', 'seo_');
        $groupby = $class.'.id, sfUrls.id';
    } else {
        $groupby = $class.'.id, Intro.intro';
    }
} else {
    $select = [$class.'id'];
    $groupby = $class.'.id';
}

// Add custom parameters
foreach (['leftJoin', 'select'] as $v) {
    if (!empty($scriptProperties[$v])) {
        $tmp = $modx->fromJSON($scriptProperties[$v]);
        if (is_array($tmp)) {
            $$v = array_merge($$v, $tmp);
        }
    }
    unset($scriptProperties[$v]);
}

// Default parameters
$default = [
    'class'             => $class,
    'leftJoin'          => $leftJoin,
    'select'            => $select,
    'groupby'           => $groupby,
    'return'            => !empty($returnIds)
        ? 'ids'
        : 'data',
    'fastMode'          => $fastMode,
    'nestedChunkPrefix' => 'msearch2_',
];
if (!empty($resources)) {
    $default['resources'] = is_array($resources)
        ? implode(',', $resources)
        : $resources;
}

if (!empty($toSortIds)) {
    $scriptProperties['sortby'] = 'FIELD(IFNULL(sfUrls.id, modResource.id), '.implode(',', $toSortIds).')';
}

// Merge all properties and run!
$mSearch2->pdoTools->setConfig(array_merge($default, $scriptProperties), false);
$mSearch2->pdoTools->addTime('Query parameters are prepared.');
$rows = $mSearch2->pdoTools->run();

$log = '';
if ($modx->user->hasSessionContext('mgr') && !empty($showLog)) {
    $log .= '<pre class="mSearchLog">'.print_r($mSearch2->pdoTools->getTime(), 1).'</pre>';
}

// Processing results
if (!empty($returnIds)) {
    $modx->setPlaceholder('mSearch.log', $log);
    if (!empty($toPlaceholder)) {
        $modx->setPlaceholder($toPlaceholder, $rows);
        return '';
    }

    return $rows;
}

if (!empty($rows) && is_array($rows)) {
    $output = [];
    foreach ($rows as $k => $row) {
        // Processing main fields
        if (isset($row['seo_id'])) {
            $row['weight'] = $found[$row['seo_id'].'::'.'sfUrls'] ?? '';
            $seoUrl = $row['seo_new_url'] ?: $row['seo_old_url'];
            $row['uri'] = $mSearch2->makeUrl($seoUrl, $row['uri'], $row['id']);
            $row['page_title'] = $row['pagetitle'] ?? '';
            $row['pagetitle'] = $row['seo_link'] ?? $row['pagetitle'];
        } else {
            $row['weight'] = $found[$row['id']] ?? '';
        }
        if ($row['introseo'] ?? false) {
            $row['intro'] = $mSearch2->Highlight($row['introseo'], $query, $htagOpen, $htagClose);
        } else {
            $row['intro'] = $mSearch2->Highlight($row['intro'], $query, $htagOpen, $htagClose);
        }

        $row['idx'] = $mSearch2->pdoTools->idx++;

        if ($returnData) {
            $output[] = $row;
        } else {
            $tplRow = $mSearch2->pdoTools->defineChunk($row);
            $output[] .= empty($tplRow)
                ? $mSearch2->pdoTools->getChunk('', $row)
                : $mSearch2->pdoTools->getChunk($tplRow, $row, $fastMode);
        }
    }
    $mSearch2->pdoTools->addTime('Returning processed chunks');
    if ($returnData) {
        return $output;
    }
    if (!empty($toSeparatePlaceholders)) {
        $output['log'] = $log;
        $modx->setPlaceholders($output, $toSeparatePlaceholders);
    } else {
        $output = implode($outputSeparator, $output).$log;
    }
} else {
    $output = $modx->lexicon('mse2_err_no_results').$log;
}

// Return output
if (!empty($tplWrapper) && (!empty($wrapIfEmpty) || !empty($output))) {
    $output = $mSearch2->pdoTools->getChunk(
        $tplWrapper,
        [
            'output'  => $output,
            'total'   => $modx->getPlaceholder($mSearch2->pdoTools->config['totalVar']),
            'query'   => $modx->getPlaceholder($plPrefix.$queryVar),
            'parents' => $modx->getPlaceholder($plPrefix.$parentsVar),
        ],
        $fastMode
    );
}

if (!empty($toPlaceholder)) {
    $modx->setPlaceholder($toPlaceholder, $output);
} else {
    return $output;
}