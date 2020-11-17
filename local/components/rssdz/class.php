<?
use Bitrix\Main\SystemException;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\HttpClient;

Loader::includeModule("highloadblock");
use Bitrix\Highloadblock as HL;
use Bitrix\Main\Entity;

class rssImport extends CBitrixComponent{
  public function executeComponent(){
    try{
        //$this->arResult['item']=$this->arParams['arrUser'];
        //$this->includeComponentTemplate();
        //проверим есть ли блок с сущностью, если нет то создадим
        $result = \Bitrix\Highloadblock\HighloadBlockTable::getList(array('filter'=>array('=NAME'=>"CategoryBand")));
        if(!$row = $result->fetch()){
            $arLangs = Array(
                'ru' => 'Категория ленты',
                'en' => 'Category band'
            );

            //создание HL-блока
            $result = HL\HighloadBlockTable::add(array(
                'NAME' => 'CategoryBand',
                'TABLE_NAME' => 'category_band_table',
            ));
            if ($result->isSuccess()) {
                $id = $result->getId();
                foreach($arLangs as $lang_key => $lang_val){
                    HL\HighloadBlockLangTable::add(array(
                        'ID' => $id,
                        'LID' => $lang_key,
                        'NAME' => $lang_val
                    ));
                }
            } else {
                //если есть ошибка покажем
                $errors = $result->getErrorMessages();
                var_dump($errors);
            }
            $UFObject = 'HLBLOCK_'.$id;
            //добавим и создадим поля
            $arCartFields = Array(
                'UF_TITLE'=>Array(
                    'ENTITY_ID' => $UFObject,
                    'FIELD_NAME' => 'UF_TITLE',
                    'USER_TYPE_ID' => 'string',
                    'MANDATORY' => 'N',
                    "EDIT_FORM_LABEL" => Array('ru'=>'Категория', 'en'=>'Categ'),
                    "LIST_COLUMN_LABEL" => Array('ru'=>'Категория', 'en'=>'Categ'),
                    "LIST_FILTER_LABEL" => Array('ru'=>'Категория', 'en'=>'Categ'),
                    "ERROR_MESSAGE" => Array('ru'=>'', 'en'=>''),
                    "HELP_MESSAGE" => Array('ru'=>'', 'en'=>''),
                ),
                'UF_XML_ID'=>Array(
                    'ENTITY_ID' => $UFObject,
                    'FIELD_NAME' => 'UF_XML_ID',
                    'USER_TYPE_ID' => 'string',
                    'MANDATORY' => 'N',
                    "EDIT_FORM_LABEL" => Array('ru'=>'Внешний код', 'en'=>'Name of dish'),
                    "LIST_COLUMN_LABEL" => Array('ru'=>'Внешний код', 'en'=>'Name of dish'),
                    "LIST_FILTER_LABEL" => Array('ru'=>'Внешний код', 'en'=>'Name of dish'),
                    "ERROR_MESSAGE" => Array('ru'=>'', 'en'=>''),
                    "HELP_MESSAGE" => Array('ru'=>'', 'en'=>''),
                ),
            );
            $arSavedFieldsRes = Array();
            foreach($arCartFields as $arCartField){
                $obUserField  = new CUserTypeEntity;
                $ID = $obUserField->Add($arCartField);
                $arSavedFieldsRes[] = $ID;
            }
            $HL = $id;
        } else {
            $HL = $row['ID'];
        }

        //теперь можно работать со списком
        $hlblock = \Bitrix\Highloadblock\HighloadBlockTable::getById($HL)->fetch();
        $entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock);
        $entityDataClass = $entity->getDataClass();
        $result = $entityDataClass::getList(array(
            "select" => array("*"),
            "order" => array("ID"=>"DESC"),
            "filter" => Array(),
        ));
        $HelpList = array();
        //получим массив списка
        while ($arRow = $result->Fetch()){
            $HelpList[$arRow['UF_NAME']] = $arRow['ID'];
        }
        
        //откуда парсим
        $url = 'https://lenta.ru/rss';
        $content = simplexml_load_file($url);
        $array = json_decode(json_encode((array)$content), TRUE);
        //парсить будем записи не старше 2-х дней
        $time = AddToTimeStamp(array("DD"=>-2), time());
        $el = new CIBlockElement;
        $rss=array();
        //получить последние записи
        $arSelect = Array("ID", "IBLOCK_ID", "NAME","PROPERTY_*");
        $arFilter = Array("IBLOCK_ID"=>IntVal($IBLOCK_ID), "ACTIVE"=>"Y", ">=PROPERTY_date"=>trim(CDatabase::CharToDateFunction(date("d.m.Y H:i:s",(time()-86400)),"\'"));
        $res = CIBlockElement::GetList(Array(), $arFilter, false, false, $arSelect);
        while($ob = $res->GetNextElement()){ 
         $arFields = $ob->GetFields();  
         $arProps = $ob->GetProperties();
         $prop=array();
         foreach($arProps as $code=>$val){
             $prop[$code] = $val['VALUE'];
         }
         $rss[]=$prop['link'];
        }
        foreach($array['channel']['item'] as $date){
            $catID=false;
            $newTime = MakeTimeStamp(FormatDateFromDB($date['pubDate'],"DD.MM.YYYY HH:MI:SS"),"DD.MM.YYYY HH:MI:SS");
            if($time < $newTime && !in_array($date['link'],$rss)){
                //если нет записей в базе то добавить
                //смотреть категорию
                if(in_array($date['category'],$HelpList)){
                    $catID = $HelpList[$date['category']];
                } else {
                    $arFields = array (
                        'UF_USER_ID' => $userID,
                    );
                    $result = $entityDataClass::add($arFields);
                    if($result->isSuccess()){                    
                        $catID = $result->getId();
                    } 
                }
                //добавим новость
                $PROP=array(
                    'link'=>$date['link'],
                    'SpravochnikCategory' => $catID,
                );
                $arLoadProductArray = Array(
                  "IBLOCK_SECTION_ID" => false,          // элемент лежит в корне раздела
                  "IBLOCK_ID"      => $IBLOCK_ID,
                  "PROPERTY_VALUES"=> $PROP,
                  "NAME"           => $date['title'],
                  "ACTIVE"         => "Y",            // активен
                  "DETAIL_TEXT"    => $date['description'],
                  );

                $NEWSID = $el->Add($arLoadProductArray);
            }
        }
    }
    catch (SystemException $e)
    {
        ShowError($e->getMessage());
    }
  }
}
