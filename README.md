##### Способ запуска поиска по SEO страницам (работает и в админке)

1. Скопировать все файлы в соответствующие директории
2. Создать копию сниппета `mSearch2` с названием `mSearch2Seo` (название не менять) с содержимым из `core/components/msearch2/elements/snippets/snippet.msearch2seo.php`
3. Создать копию плагина `mSearch2` с названием `mSearch2Seo` с содержимым из `core/components/msearch2/elements/plugins/plugin.msearch2seo.php`
4. Создать системную настройку `msearch2.action_url` со значением `/assets/components/msearch2/action-search.php`
5. Создать системную настройку `mse2_seo_index_empty` с типом **Да/нет** со значением `1` (разрешить индекс пустых SEO страниц)
6. Создать системную настройку `mse2_seo_index_fields` с типом **Текст** со значением `seo_word:5,seo_link:4,seo_title:1,seo_h1:1,seo_description:1,rule_title:1,rule_content:1,rule_h1:1` (развесовка полей ссылок и правил)
7. Отредактировать пункт меню mSearch2 в админке `/manager/?a=system/action`, изменив действие `home` на `seohome`
8. В вызове **mSearchForm** указать ``` &element=`mSearch2Seo` ```
9. Заменить вызовы **mSearch2** на **mSearch2Seo** (при pdoPage на ``` &element=`mSearch2Seo` ```)
10. Запустить обновление индекса в админке: `/manager/?a=seohome&namespace=msearch2`