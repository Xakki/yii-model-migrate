Yii_model_migratre
==================

В конфиге commandMap прописать

'modelmigrate' => array('class' => '\extensions\ModelMigration\ModelMigrationCommand', 'connectionID'    => 'db')


запуск, в корне проекта, указать класс -  

#php yiic.php modelmigrate index "app\services\statistics\UserAction"

* Подхвачивает данные из extend модели
* Создает фаил миграции 
* Определяет добавленные и удаленные атрибуты
* Создание составных индексов и ключей

Планируется 
* определять измененные атрибуты

-----------------
Правила документирования модели

Возможно укзать соединение с БД (или используется текущее соединение по умолчанию)
 * #connectionId getDbStats     !!! вызов функции если есть
 * #connectionId db_stats       !!! Используемый компонент для подключения к БД

Атрибуты
 * @property integer    $attribute      строго после описния поля в квадратных скобках задается параметры sql  [int2 NOT NULL]
 * @property string     $attribute2     Тип     [varchar(255)]

Если нужно исключить атрибут взаимствованный из extends класса
 * @exclude  $attribute

Составной первичный ключ
 * @sqlPrimary     type,date,company_id,mask
 * !!! или можно в атрибутах было прописать так
 * @property id     $id     первичный ключ     [pk]

Индексы
 * @sqlIndex    company_id_idx  company_id

Уникальный индекс (Unique)
 * @sqlIndex    company_id_idx  company_id  true
