<?php

namespace sidanval\tabular;

use Yii;
use yii\base\Model;
use yii\db\ActiveRecord;

/**
 * Class IntegrationParametersForm
 * @package frontend\models
 *
 * @property array $models
 */
class TabularForm extends Model
{
    const EVENT_BEFORE_DELETE = 'beforeDelete';

    const EVENT_AFTER_DELETE = 'afterDelete';

    const EVENT_BEFORE_SAVE = 'beforeSave';

    const EVENT_AFTER_SAVE = 'afterSave';

    const EVENT_BEFORE_MODEL_DELETE = 'beforeModelDelete';

    const EVENT_AFTER_MODEL_DELETE = 'afterModelDelete';

    const EVENT_BEFORE_MODEL_SAVE = 'beforeModelSave';

    const EVENT_AFTER_MODEL_SAVE = 'afterModelSave';

    /** @var  ActiveRecord */
    public $rootModel;

    /** @var  string */
    public $rootModelAttribute;

    /** @var  string */
    public $modelsAttribute;

    /** @var bool  */
    public $withRoot = false;

    /** @var  ActiveRecord[] */
    protected $models;

    /** @var  \Closure */
    public $modelsGetter;

    /** @var  \Closure */
    public $deleteCallback;

    /** @var  \Closure */
    public $deleteModelCallback;

    /** @var  \Closure */
    public $saveCallback;

    /** @var  \Closure */
    public $saveModelCallback;

    /**
     * @param array $data
     * @param null $formName
     * @return bool
     */
    public function load($data, $formName = null, $rootFormName = null)
    {
        $relation = $this->rootModel->getRelation($this->rootModelAttribute);
        $relationCLass = $relation->modelClass;
        $templateModel = new $relationCLass();
        $templateModelPk = reset($templateModel->primaryKey());

        if($this->withRoot) {
            $this->rootModel->load($data, $rootFormName);
        }

        if($formName === null) {
            $formName = $templateModel->formName();
        }

        if(!isset($data[$formName])) {
            return false;
        }

        $parametersData = $data[$formName];
        $this->models = [];
        foreach ($parametersData as $index => $value) {
            $this->models[$index] = new $relationCLass();
            if(isset($value[$templateModelPk]) && $value[$templateModelPk]) {
                $this->models[$index] = $templateModel::findOne($value[$templateModelPk]) ?: $this->models[$index];
            }
        }
        Model::loadMultiple($this->models, Yii::$app->request->post());

        return true;
    }

    /**
     * @param null $attributeNames
     * @param bool $clearErrors
     * @return bool
     */
    public function validate($attributeNames = null, $clearErrors = true)
    {
        $result = true;

        if($this->withRoot) {
            $result = $this->rootModel->validate($attributeNames, $clearErrors);
        }

        $this->trigger(self::EVENT_BEFORE_VALIDATE);

        $result = $result && Model::validateMultiple($this->models);

        $this->trigger(self::EVENT_AFTER_VALIDATE);

        return $result;
    }

    /**
     * @param bool $runValidation
     * @return bool
     */
    public function save($runValidation = true)
    {
        if($runValidation && !$this->validate()) {
            return false;
        }

        $transaction = Yii::$app->db->beginTransaction();

        if($this->withRoot) {
            $this->rootModel->save();
        }

        $this->deleteOldModels();

        $this->saveNewModels();

        $transaction->commit();

        return true;
    }

    /**
     * @return bool
     */
    protected function deleteOldModels()
    {
        $result = true;

        $this->trigger(self::EVENT_BEFORE_DELETE);

        if($this->deleteCallback !== null && is_callable($this->deleteCallback)) {
            $result = call_user_func($this->deleteCallback, $this->getModels(true));
            $result = is_bool($result) ? $result : true;
        } else {
            foreach ($this->getModels(true) as $model) {
                $this->trigger(self::EVENT_BEFORE_MODEL_DELETE, new TabularEvent(['sender' => $this, 'model' => $model]));

                $model->delete();

                $this->trigger(self::EVENT_AFTER_MODEL_DELETE, new TabularEvent(['sender' => $this, 'model' => $model]));
            }
        }

        $this->trigger(self::EVENT_AFTER_DELETE);

        return $result;
    }

    /**
     * @return bool
     */
    public function saveNewModels()
    {
        $result = true;

        $this->trigger(self::EVENT_BEFORE_SAVE);

        if($this->saveCallback !== null && is_callable($this->saveCallback)) {
            $result = call_user_func($this->saveCallback, $this->models);
            $result = is_bool($result) ? $result : true;
        } else {
            foreach ($this->models as $model) {
                $this->trigger(self::EVENT_BEFORE_MODEL_SAVE, new TabularEvent(['sender' => $this, 'model' => $model]));

                $model->link($this->modelsAttribute, $this->rootModel);

                $this->trigger(self::EVENT_AFTER_MODEL_SAVE, new TabularEvent(['sender' => $model, 'model' => $model]));
            }
        }

        $this->trigger(self::EVENT_AFTER_SAVE);

        return $result;
    }

    /**
     * @param bool $current
     * @return array
     */
    public function getModels($current = false)
    {
        if($this->models === null || $current) {
            if($this->modelsGetter && is_callable($this->modelsGetter)) {
                return call_user_func($this->modelsGetter, $this->rootModel);
            }

            return $this->rootModel->{$this->rootModelAttribute};
        }

        return $this->models;
    }
}