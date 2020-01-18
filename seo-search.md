##### Способ запуска поиска по SEO страницам

1. Добавить файл `msearch2seo.class.php`  в каталог `/core/components/msearch2/model/msearch2/`
2. Сделать копию файла по адресу `/assets/components/msearch2/action.php` на `action-search.php`
3. Заменить в файле `action-search.php` на строке ~44:  
  `$mSearch2 = $modx->getService('msearch2', 'mSearch2',`  
  на `$mSearch2 = $modx->getService('msearch2seo', 'mSearch2Seo',`
4. Создать системную настройку `msearch2.action_url` со значением `/assets/components/msearch2/action-search.php`
5. Для работы и проверки поиска в админке:
    1. добавить файл `seohome.class.php` сюда `/core/components/msearch2/controllers/`    
    2. Добавить файл `seosearch.grid.js` в папку `/assets/components/msearch2/js/mgr/widgets/`
    3. Добавить файл `seogetlist.class.php` в папку `/core/components/msearch2/processors/mgr/search/`
    4. Отредактировать пункт меню mSearch2 в админке `/manager/?a=system/action`, изменив действие `home` на `seohome`
6. 