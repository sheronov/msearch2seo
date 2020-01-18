##### Способ запуска поиска по SEO страницам

1. Добавить файл `msearch2seo.class.php`  в каталог `/core/components/msearch2/model/msearch2/`
2. Сделать копию файла по адресу `/assets/components/msearch2/action.php` на `action-search.php`
3. Заменить в файле `action-search.php` на строке ~44:  
  `$mSearch2 = $modx->getService('msearch2', 'mSearch2',`  
  на `$mSearch2 = $modx->getService('msearch2seo', 'mSearch2Seo',`
4. Создать системную настройку `msearch2.action_url` со значением `/assets/components/msearch2/action-search.php`
5. Для работы в админке добавить файл `seohome.class.php` сюда `/core/components/msearch2/controllers/`  
  и отредактировать пункт меню mSearch2 в админке `/manager/?a=system/action`, изменив действие `home` на `seohome`.
