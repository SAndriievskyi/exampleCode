<?php

namespace app\modules\gmail\models\search;

use app\models\search\SearchInterface;
use app\modules\gmail\models\reference\UserEmail;
use app\validators\DateRangeValidator;
use app\validators\MultiEnumValidator;
use app\validators\MultiReferenceValidator;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;

class UserEmailSearch extends UserEmail implements SearchInterface
{
    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['name', 'email',], 'string', 'max' => 255,],
            [['create_user_id', 'update_user_id',], MultiReferenceValidator::class, 'explodeAttribute' => true],
            [['reference_status_id'], MultiEnumValidator::class, 'explodeAttribute' => true,],
            [['created_at', 'updated_at'], DateRangeValidator::class, 'explodeAttribute' => true,],
        ];
    }

    /**
     * @inheritdoc
     */
    public function scenarios()
    {
        return ActiveRecord::scenarios();
    }

    /**
     * @inheritdoc
     */
    public function getColumns()
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return UserEmail::tableName();
    }

    /**
     * @inheritdoc
     */
    public function searchModels($params)
    {
        $query = UserEmail::find();

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
        ]);

        if (!$this->validate()) {
            $query->andWhere("1=0");

            return $dataProvider;
        }

        $this->addDateSearchQuery('updated_at', $query, 'updated_at');
        $this->addDateSearchQuery('created_at', $query, 'created_at');
        $query->andFilterWhere(['create_user_id' => $this->create_user_id])
            ->andFilterWhere(['update_user_id' => $this->update_user_id])
            ->andFilterWhere(["ilike", 'name', $this->name])
            ->andFilterWhere(["ilike", 'email', $this->email]);

        return $dataProvider;
    }
}