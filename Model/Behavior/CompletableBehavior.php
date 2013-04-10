<?php
/**
* Completable Behavior
*
* PHP 5.3
*
*
* Licensed under The MIT License
* Redistributions of files must retain the above copyright notice.
*
* @version       0.1
* @link          https://github.com/krolow/Komplete
* @package       Komplete.Model.Behavior.CompletableBehavior
* @author        Vinícius Krolow <krolow@gmail.com>
* @license       MIT License (http://www.opensource.org/licenses/mit-license.php)
*/
class CompletableBehavior extends ModelBehavior {
    

    public $settings;

    /**
     * Setup this behavior with the specified configuration settings.
     *
     * @param Model $model  Model using this behavior
     * @param array $config Configuration settings for $model
     *
     * @return void
     * @access public
     */
    public function setup(Model $model, $config = array()) {
        $this->settings[$model->alias] = $config;
    }

    /**
     * beforeSave is called before a model is saved.  Returning false from a beforeSave callback
     * will abort the save operation.
     *
     * @param Model $model Model using this behavior
     * 
     * @return mixed False if the operation should abort. Any other result will continue.
     * @access  public
     */
    public function beforeSave(Model $model) {
        $separator = $this->settings[$model->alias]['separator'];
        
        foreach ($this->settings[$model->alias]['relations'] as $relation => $value) {
            if (!isset($model->data[$model->alias][$relation])) {
                continue;
            }

            $model->set(
                $this->insertDataInModel(
                    $model,
                    $relation,
                    $this->processKeywords($model, $relation, $value)
                )
            );

        }

        return true;
    }


    public function prepareData(Model $model) {
        $separator = $this->settings[$model->alias]['separator'];

        foreach ($this->settings[$model->alias]['relations'] as $relation => $value) {
            if (!isset($model->data[$model->alias][$relation])) {
                continue;
            }

            $model->set(
                $this->insertDataInModel(
                    $model,
                    $relation,
                    $this->processKeywords($model, $relation, $value)
                )
            );

        }

        return true;
    }


    /**
     * Insert the data inside the model
     * 
     * @param Model  $model  Model using this behavior
     * @param string $relation  The relation to insert data
     * @param string $processed  The keyword proccessed
     * 
     * @return  array
     * @access  protected
     */
    protected function insertDataInModel(Model $model, $relation, $processed)
    {

        unset($model->data[$model->alias][$relation]);

        if (is_string($processed)) {
            $assocs = $model->getAssociated();

            $foreignKey = ($model->{$assocs[$relation]}[$relation]['foreignKey']);
            $model->data[$model->alias][$foreignKey] = $processed;


            return $model->data;
        }

        /* Regular usage for other relations */

        if(empty($this->settings[$model->alias]['relations'][$relation]['hasManyThrough'])){
            
            $model->data[$relation] = array(
                $relation => $processed
            );
            return $model->data;
        }

        /* In order to correctly use a HasManyThrough it must be declared in the actsAs config*/ 
        /* It has to be added as hasManyThrough in the same way the field and multiple fields*/


        if(!empty($this->settings[$model->alias]['relations'][$relation]['hasManyThrough'])){

            foreach ($processed as $process){
                $model->data[$relation][] = $process;    
            }
            return $model->data;
            
        }

        
    }

    /**
     * Normalize the given keyword
     * 
     * @param Model  $model  Model using this behavior
     * @param string $relation  The relation to insert data
     * @param string $value  The given config of the relation
     * 
     * @return  array
     * @access  protected
     */
    protected function processKeywords(Model $model, $relation, $value) {

        $keyword = $model->data[$model->alias][$relation];
            
        if ($value['multiple'] == false && is_array($keyword)){

            return $this->processMultipleFieldsRelation($model, $relation, $keyword);
        }

        if ($value['multiple'] == true && is_array($keyword)){

            if (isset($value['hasManyThrough'])){
                return $this->processMultipleEntriesHMTRelation($model, $relation, $keyword, $value);
            }

            if (!isset($value['hasManyThrough'])){
                return $this->processMultipleEntriesRelation($model, $relation, $keyword, $value);   
            }
            
            
        }

        if (!isset($value['multiple']) || $value['multiple'] == false) {

            return $this->processSingleKeywordRelation($model, $relation, $keyword); 
        }

        return $this->processMultipleKeywordRelation($model, $relation, $keyword);
    }


    /**
    *   Has Many Through to save data in the linking table
    *   
    */
    protected function processMultipleEntriesRelation(Model $model, $relation, $keywords)
    {

        foreach ($keywords as $keyword){
            $keyword = $this->getKeyword($model, $relation, $keyword);

            if (!$keyword) {
                $keyword = $this->addMultipleFieldsKeyword($model, $relation, $value);
            }
            
            $keyword[$relation][$model->{$relation}->primaryKey] = $model->{$relation}->getLastInsertId();
            $savedData[] = $keyword[$relation][$model->{$relation}->primaryKey];

        }

        return $savedData;
    }


    /**
    *   Has Many Through to save data in the linking table
    *   
    */
    protected function processMultipleEntriesHMTRelation(Model $model, $relation, $keywords, $value)
    {

        foreach ($keywords as &$keyword){

            foreach ($keyword as $key => $word ){

                if ($key == $value['hasManyThrough']){

                    $backup = $word;
                    $word = $model->NetworksActivity->Activity->find(
                        'first',
                        array(
                            'conditions' => array(
                                $this->getSearchFieldOfRelation($model, $relation) => $word
                            )
                        )
                    );

                    if ($word){
                        $keyword['activity_id'] = (int) $word['Activity']['id'];
                    }

                    if (!$word) {

                        $toSave = array(
                            'Activity' => array(
                                $this->getSearchFieldOfRelation($model, $relation) => $backup
                            )
                        );

                        $model->NetworksActivity->Activity->create();
                        $word = $model->NetworksActivity->Activity->save($toSave);
                        $keyword['activity_id'] = (int) $model->NetworksActivity->Activity->getLastInsertId();

                    }
                    
                    unset($keyword['Activity']);

                }
            }

        }

        return $keywords;
    }

    /**
    *
    *
    */
    protected function processMultipleFieldsRelation(Model $model, $relation, $keyword) {

        $search = $this->getSearchField($model, $relation, $keyword);
        $value = $keyword;
        $keyword = $this->getKeyword($model, $relation, $search);

        if (!$keyword) {
            $keyword = $this->addMultipleFieldsKeyword($model, $relation, $value);
        }

        $keyword[$relation][$model->{$relation}->primaryKey] = $model->{$relation}->getLastInsertId();
        $savedData[] = $keyword[$relation][$model->{$relation}->primaryKey];

        return $savedData;
    }

    protected function getSearchField($model, $relation, $keyword) 
    {

        $data = $this->settings[$model->alias]['relations'][$relation];

        if (!empty($data['virtual'])) {

            foreach ($data['virtual'] as $field) {
                $searchField[$field] = $keyword[$field];
            }
            
            $field = implode(' ', $searchField);

            return $field;    
        }

        return false;
    }


    /**
     * Normalize when it is a single keyword
     * 
     * @param Model  $model  Model using this behavior
     * @param string $relation  The relation to insert data
     * @param string $keyword  The given config of the relation
     * 
     * @return  array
     * @access  protected
     */    
    protected function processSingleKeywordRelation(Model $model, $relation, $keyword) {
        $value = trim($keyword);
        $keyword = $this->getKeyword($model, $relation, $value);

        if (!$keyword) {
            $keyword = $this->addKeyword($model, $relation, $value);
        }

        return $keyword[$relation][$model->{$relation}->primaryKey];
    }

    /**
     * Normalize when it is a single keyword
     * 
     * @param Model  $model  Model using this behavior
     * @param string $relation  The relation to insert data
     * @param string $keyword  The given config of the relation
     * 
     * @return  array
     * @access  protected
     */  
    protected function processMultipleKeywordRelation(Model $model, $relation, $keyword) {
        $keywords = explode(', ', $keyword);

        $dataToSave = array();
        foreach ($keywords as $keyword) {
            $key = $this->processSingleKeywordRelation($model, $relation, $keyword);
            $dataToSave[] = $key;
        }
        
        return $dataToSave;
    }
    
    /**
     * Look for at database for the given keyword
     * 
     * @param Model  $model  Model using this behavior
     * @param string $relation  The relation to insert data
     * @param string $keyword  The given config of the relation
     * 
     * @return  array
     * @access  protected
     */  
    protected function getKeyword(Model $model, $relation, $keyword)
    {

        return $model->{$relation}->find(
            'first',
            array(
                'conditions' => array(
                    $this->getSearchFieldOfRelation($model, $relation) => $keyword
                )
            )
        );
    }

    /**
     * Inser the given keyword at database
     * 
     * @param Model  $model  Model using this behavior
     * @param string $relation  The relation to insert data
     * @param string $keyword  The given config of the relation
     * 
     * @return  midex
     * @access  protected
     */  
    protected function addKeyword($model, $relation, $keyword)
    {
        $toSave = array(
            $relation => array(
                $this->getSearchFieldOfRelation($model, $relation) => $keyword
            )
        );

        $model->{$relation}->create();
        $data = $model->{$relation}->save($toSave);
        $data[$relation][$model->{$relation}->primaryKey] = $model->{$relation}->getLastInsertId();
        
        return $data;
    }

     /**
     * Insert the given fields in database
     * 
     * @param Model  $model  Model using this behavior
     * @param string $relation  The relation to insert data
     * @param string $keyword  The given config of the relation
     * 
     * @return  midex
     * @access  protected
     */
    protected function addMultipleFieldsKeyword($model, $relation, $keyword)
    {
        $toSave = array(
            $relation => $keyword
        );

        $model->{$relation}->create();

        $data = $model->{$relation}->save($toSave);

        $data[$relation][$model->{$relation}->primaryKey] = $model->{$relation}->getLastInsertId();

        return $data;

    }

    /**
     * Search for the existence of the given keyword
     * 
     * @param Model  $model  Model using this behavior
     * @param string $relation  The relation to insert data
     * @param string $keyword  The given config of the relation
     * 
     * @return  array
     * @access  public
     */  
    public function search(Model $model, $relation, $keyword) {
        $data = $model->{$relation}->find(
            'list',
            array(
                'conditions' => array(
                    $this->getSearchFieldOfRelation($model, $relation) . ' LIKE ' => '%' . $keyword . '%'
                )
            )
        );

        $options = array();
        foreach ($data as $value) {
            array_push($options, $value);
        }
        
        return $options;        
    }

    /**
     * Retrive what is the search field for the given keyword
     * 
     * @param Model  $model  Model using this behavior
     * @param string $relation  The relation to insert data
     * 
     * @return  string
     * @access  protected
     */
    protected function getSearchFieldOfRelation(Model $model, $relation) {
        return $this->settings[$model->alias]['relations'][$relation]['field'];
    }

}