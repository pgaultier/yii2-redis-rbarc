<?php
/**
 * DataService.php
 *
 * PHP version 5.6+
 *
 * @author Philippe Gaultier <pgaultier@sweelix.net>
 * @copyright 2010-2016 Philippe Gaultier
 * @license http://www.sweelix.net/license license
 * @version XXX
 * @link http://www.sweelix.net
 * @package sweelix\rbac\redis
 */

namespace sweelix\rbac\redis;

use sweelix\guid\Guid;
use sweelix\rbac\redis\DuplicateKeyException;
use yii\base\InvalidCallException;
use yii\base\InvalidParamException;
use yii\base\Object;
use yii\di\Instance;
use yii\rbac\Assignment;
use yii\rbac\Item;
use yii\rbac\Permission;
use yii\rbac\Role;
use yii\rbac\Rule;
use yii\redis\Connection;
use Yii;

/**
 * REDIS dataservice allow the developper to update the database easily
 *
 * database structure :
 *   * auth:users:<userId>:assignments : ZSET (string: roleName, score: createdAt)
 *   * auth:roles:<roleName>:assignments : ZSET (string|int: userId, score: createdAt)
 *   * auth:rules:<ruleName> : MAP (string: ruleName, string: data, integer: createdAt, integer: updatedAt)
 *   * auth:types:<typeId>:items : SET (string: itemName)
 *   * auth:items:<itemName> : MAP (string: itemName, int: typeId, string: description, integer: createdAt, integer: updatedAt, string: ruleName)
 *   * auth:rules:<ruleName>:items : SET (string: itemName)
 *   * auth:items:<itemName>:children : SET (string: itemName)
 *   * auth:items:<itemName>:parents : SET (string: itemName)
 *
 * @author Philippe Gaultier <pgaultier@sweelix.net>
 * @copyright 2010-2016 Philippe Gaultier
 * @license http://www.sweelix.net/license license
 * @version XXX
 * @link http://www.sweelix.net
 * @package sweelix\rbac\redis
 * @since XXX
 */

class DataService extends Object
{
    /**
     * @var Connection|array|string the Redis DB connection object or the application component ID of the DB connection.
     */
    public $db = 'redis';

    /**
     * @var string
     */
    public $userAssignmentsKey = 'auth:users:{id}:assignments';

    /**
     * @var string
     */
    public $roleAssignmentsKey = 'auth:roles:{id}:assignments';

    /**
     * @var string
     */
    public $ruleKey = 'auth:rules:{id}';

    /**
     * @var string
     */
    public $typeItemsKey = 'auth:types:{id}:items';

    /**
     * @var string
     */
    public $itemKey = 'auth:items:{id}';

    /**
     * @var string
     */
    public $ruleItemsKey = 'auth:rules:{id}:items';

    /**
     * @var string
     */
    public $itemChildrenKey = 'auth:items:{id}:children';

    /**
     * @var string
     */
    public $itemParentsKey = 'auth:items:{id}:parents';

    /**
     * @var string
     */
    public $itemMappings = 'auth:mappings:items';

    /**
     * @var string
     */
    public $itemMappingsGuid = 'auth:mappings:itemsguid';

    /**
     * @var string
     */
    public $ruleMappings = 'auth:mappings:rules';

    /**
     * @var string
     */
    public $ruleMappingsGuid = 'auth:mappings:rulesguid';


    /**
     * @param string|integer $userId user id
     * @return string the user assignments key
     * @since XXX
     */
    public function getUserAssignmentsKey($userId)
    {
        return str_replace('{id}', $userId, $this->userAssignmentsKey);
    }

    /**
     * @param string $roleGuid role guid
     * @return string the rule assignments key
     * @since XXX
     */
    public function getRoleAssignmentsKey($roleGuid)
    {
        return str_replace('{id}', $roleGuid, $this->roleAssignmentsKey);
    }

    /**
     * @param string $ruleGuid rule guid
     * @return string the rule key
     * @since XXX
     */
    public function getRuleKey($ruleGuid)
    {
        return str_replace('{id}', $ruleGuid, $this->ruleKey);
    }

    /**
     * @param integer $typeId type id
     * @return string the type id key
     * @since XXX
     */
    public function getTypeItemsKey($typeId)
    {
        return str_replace('{id}', $typeId, $this->typeItemsKey);
    }

    /**
     * @param string $itemGuid item guid
     * @return string
     * @since XXX
     */
    public function getItemKey($itemGuid)
    {
        return str_replace('{id}', $itemGuid, $this->itemKey);
    }

    /**
     * @param string $ruleGuid rule guid
     * @return string the rule items key
     * @since XXX
     */
    public function getRuleItemsKey($ruleGuid)
    {
        return str_replace('{id}', $ruleGuid, $this->ruleItemsKey);
    }

    /**
     * @param string $itemGuid item guid
     * @return string the item children key
     * @since XXX
     */
    public function getItemChildrenKey($itemGuid)
    {
        return str_replace('{id}', $itemGuid, $this->itemChildrenKey);
    }

    /**
     * @param string $itemGuid item guid
     * @return string the item parents key
     * @since XXX
     */
    public function getItemParentsKey($itemGuid)
    {
        return str_replace('{id}', $itemGuid, $this->itemParentsKey);
    }

    /**
     * @return string the item mapping key
     * @since XXX
     */
    public function getItemMappingKey()
    {
        return $this->itemMappings;
    }

    /**
     * @return string the rule mapping key
     * @since XXX
     */
    public function getRuleMappingKey()
    {
        return $this->ruleMappings;
    }

    /**
     * @return string the item mapping key
     * @since XXX
     */
    public function getItemMappingGuidKey()
    {
        return $this->itemMappingsGuid;
    }

    /**
     * @return string the rule mapping key
     * @since XXX
     */
    public function getRuleMappingGuidKey()
    {
        return $this->ruleMappingsGuid;
    }

    /**
     * Initializes the application component.
     * This method overrides the parent implementation by establishing the database connection.
     */
    public function init()
    {
        parent::init();
        $this->db = Instance::ensure($this->db, Connection::className());
    }

    /**
     * @param Rule $rule rule to add
     * @return bool
     * @since XXX
     * @throws \sweelix\rbac\redis\DuplicateKeyException
     */
    public function addRule(Rule $rule)
    {
        if(empty($rule->name) === true) {
            throw new InvalidParamException("Rule name must be defined");
        }
        $ruleExists = (int)$this->db->executeCommand('HEXISTS', [$this->getRuleMappingKey(), $rule->name]);
        if ($ruleExists === 1)
        {
            throw new DuplicateKeyException("Rule '{$rule->name}' already defined");
        }
        $guid = Guid::v4();
        $time = time();
        if ($rule->createdAt === null) {
            $rule->createdAt = $time;
        }
        if ($rule->updatedAt === null) {
            $rule->updatedAt = $time;
        }
        $this->db->executeCommand('MULTI');
        $this->db->executeCommand('HSET', [$this->getRuleMappingKey(), $rule->name, $guid]);
        $this->db->executeCommand('HSET', [$this->getRuleMappingGuidKey(),$guid, $rule->name]);
        $this->db->executeCommand('HMSET', [$this->getRuleKey($guid),
            'data', serialize($rule),
            'createdAt', $rule->createdAt,
            'updatedAt', $rule->updatedAt
        ]);

        $this->db->executeCommand('EXEC');
        return true;
    }

    /**
     * @param string $name old rule name
     * @param Rule $rule rule to update
     * @return bool
     * @since XXX
     */
    public function updateRule($name, Rule $rule)
    {
        $rule->updatedAt = time();

        $guid = $this->db->executeCommand('HGET', [$this->getRuleMappingKey(), $name]);

        $this->db->executeCommand('MULTI');
        if ($name !== $rule->name) {
            // delete old mapping
            $this->db->executeCommand('HDEL', [$this->getRuleMappingKey(), $name]);
            // add new mapping
            $this->db->executeCommand('HSET', [$this->getRuleMappingKey(), $rule->name, $guid]);
            $this->db->executeCommand('HSET', [$this->getRuleMappingGuidKey(),$guid, $rule->name]);
        }
        $this->db->executeCommand('HMSET', [$this->getRuleKey($guid),
            'data', serialize($rule),
            'createdAt', $rule->createdAt,
            'updatedAt', $rule->updatedAt
        ]);

        $this->db->executeCommand('EXEC');
        return true;
    }

    /**
     * @param string $name rule name
     * @return null|Rule
     * @since XXX
     */
    public function getRule($name)
    {
        $rule = null;
        $guid = $this->db->executeCommand('HGET', [$this->getRuleMappingKey(), $name]);
        if ($guid !== null)
        {
            $data = $this->db->executeCommand('HGET', [$this->getRuleKey($guid), 'data']);
            $rule = unserialize($data);
        }
        return $rule;
    }

    /**
     * @param Rule $rule
     * @return bool
     * @since XXX
     */
    public function removeRule(Rule $rule)
    {
        $guid = $this->db->executeCommand('HGET', [$this->getRuleMappingKey(), $rule->name]);

        $ruleMembers = $this->db->executeCommand('SMEMBERS', [$this->getRuleItemsKey($guid)]);

        $this->db->executeCommand('MULTI');
        // delete mapping
        $this->db->executeCommand('HDEL', [$this->getRuleMappingKey(), $rule->name]);
        $this->db->executeCommand('HDEL', [$this->getRuleMappingGuidKey(), $guid]);
        // detach items
        foreach($ruleMembers as $itemGuid)
        {
            $this->db->executeCommand('HDEL', [$this->getItemKey($itemGuid), 'ruleGuid']);
        }
        // delete rule <-> item link
        $this->db->executeCommand('DEL', [$this->getRuleItemsKey($guid)]);
        // delete rule
        $this->db->executeCommand('DEL', [$this->getRuleKey($guid)]);
        $this->db->executeCommand('EXEC');
        return true;
    }

    /**
     * @return Rule[]
     * @since XXX
     */
    public function getRules()
    {
        $ruleNames = $this->db->executeCommand('HKEYS', [$this->getRuleMappingKey()]);
        $rules = [];
        foreach ($ruleNames as $ruleName)
        {
            $rules[$ruleName] = $this->getRule($ruleName);
        }
        return $rules;
    }

    /**
     * @param Item $item
     * @return bool
     * @since XXX
     * @throws \sweelix\rbac\redis\DuplicateKeyException
     */
    public function addItem(Item $item)
    {
        if (empty($item->name) === true) {
            throw new InvalidParamException("Item name must be defined");
        }
        $itemExists = (int)$this->db->executeCommand('HEXISTS', [$this->getItemMappingKey(), $item->name]);
        if ($itemExists === 1)
        {
            throw new DuplicateKeyException("Rule '{$item->name}' already defined");
        }
        $guid = Guid::v4();
        $time = time();
        if ($item->createdAt === null) {
            $item->createdAt = $time;
        }
        if ($item->updatedAt === null) {
            $item->updatedAt = $time;
        }
        $ruleGuid = null;
        if (empty($item->ruleName) === false) {
            $ruleGuid = $this->db->executeCommand('HGET', [$this->getRuleMappingKey(), $item->ruleName]);
        }

        $this->db->executeCommand('MULTI');
        // update mapping
        $this->db->executeCommand('HSET', [$this->getItemMappingKey(), $item->name, $guid]);
        $this->db->executeCommand('HSET', [$this->getItemMappingGuidKey(), $guid, $item->name]);
        $insertItem = [$this->getItemKey($guid),
            'data', serialize($item->data),
            'type', $item->type,
            'createdAt', $item->createdAt,
            'updatedAt', $item->updatedAt
        ];
        if ($item->description !== null) {
            $insertItem[] = 'description';
            $insertItem[] = $item->description;
        }
        if ($ruleGuid !== null) {
            $insertItem[] = 'ruleGuid';
            $insertItem[] = $ruleGuid;
        }
        // insert item
        $this->db->executeCommand('HMSET', $insertItem);
        $this->db->executeCommand('SADD', [$this->getTypeItemsKey($item->type), $guid]);
        // affect rule
        if ($ruleGuid !== null) {
            $this->db->executeCommand('SADD', [$this->getRuleItemsKey($ruleGuid), $guid]);
        }
        $this->db->executeCommand('EXEC');
        return true;
    }

    /**
     * @param string $name item name
     * @return null|Permission|Role
     * @since XXX
     */
    public function getItem($name)
    {
        $item = null;
        $guid = $this->db->executeCommand('HGET', [$this->getItemMappingKey(), $name]);
        if ($guid !== null)
        {
            $data = $this->db->executeCommand('HGETALL', [$this->getItemKey($guid)]);
            $dataRow = ['name' => $name];
            for ($i = 0; $i < count($data); $i = $i + 2) {
                $dataRow[$data[$i]] = $data[($i + 1)];
            }
            if (isset($dataRow['ruleGuid']) === true) {
                $ruleName = $this->db->executeCommand('HGET', [$this->getRuleMappingGuidKey(), $dataRow['ruleGuid']]);
                if ($ruleName !== null) {
                    $dataRow['ruleName'] = $ruleName;
                }
                unset($dataRow['ruleGuid']);
            }
            $item = $this->populateItem($dataRow);
        }
        return $item;

    }

    /**
     * @param Item $item item to remove
     * @return bool
     * @since XXX
     */
    public function removeItem(Item $item)
    {
        $guid = $this->db->executeCommand('HGET', [$this->getItemMappingKey(), $item->name]);
        $ruleGuid = $this->db->executeCommand('HGET', [$this->getRuleMappingKey(), $item->ruleName]);

        $parentGuids = $this->db->executeCommand('SMEMBERS', [$this->getItemParentsKey($guid)]);
        $childrenGuids = $this->db->executeCommand('SMEMBERS', [$this->getItemChildrenKey($guid)]);

        $this->db->executeCommand('MULTI');
        // delete mapping
        $this->db->executeCommand('HDEL', [$this->getItemMappingKey(), $item->name]);
        $this->db->executeCommand('HDEL', [$this->getItemMappingGuidKey(), $guid]);
        // delete rule <-> item link
        $this->db->executeCommand('SREM', [$this->getRuleItemsKey($ruleGuid), $guid]);
        $this->db->executeCommand('SREM', [$this->getTypeItemsKey($item->type), $guid]);
        // detach from hierarchy
        foreach($parentGuids as $parentGuid) {
            $this->db->executeCommand('SREM', [$this->getItemChildrenKey($parentGuid), $guid]);
        }
        // detach children
        foreach($childrenGuids as $childGuid) {
            $this->db->executeCommand('SREM', [$this->getItemParentsKey($childGuid), $guid]);
        }
        $this->db->executeCommand('DEL', [$this->getItemParentsKey($guid)]);
        $this->db->executeCommand('DEL', [$this->getItemChildrenKey($guid)]);
        // delete rule
        $this->db->executeCommand('DEL', [$this->getItemKey($guid)]);
        $this->db->executeCommand('EXEC');
        return true;
    }

    /**
     * @param string $name old item name
     * @param Item $item
     * @return bool
     * @since XXX
     */
    public function updateItem($name, Item $item)
    {
        $item->updatedAt = time();

        $guid = $this->db->executeCommand('HGET', [$this->getItemMappingKey(), $name]);
        $ruleGuid = null;
        if (empty($item->ruleName) === false) {
            $ruleGuid = $this->db->executeCommand('HGET', [$this->getRuleMappingKey(), $item->ruleName]);
        }
        list($currentRuleGuid, $currentType) = $this->db->executeCommand('HMGET', [$this->getItemKey($guid), 'ruleGuid', 'type']);

        $this->db->executeCommand('MULTI');
        if ($name !== $item->name) {
            // delete old mapping
            $this->db->executeCommand('HDEL', [$this->getItemMappingKey(), $name]);
            // add new mapping
            $this->db->executeCommand('HSET', [$this->getItemMappingKey(), $item->name, $guid]);
            $this->db->executeCommand('HSET', [$this->getItemMappingGuidKey(), $guid, $item->name]);
        }

        $updateEmptyItem = [$this->getItemKey($guid)];
        $updateItem = [$this->getItemKey($guid),
            'data', serialize($item->data),
            'type', $item->type,
            'createdAt', $item->createdAt,
            'updatedAt', $item->updatedAt
        ];
        if ($item->description !== null) {
            $updateItem[] = 'description';
            $updateItem[] = $item->description;
        } else {
            $updateEmptyItem[] = 'description';
        }
        if ($ruleGuid !== $currentRuleGuid) {
            if ($currentRuleGuid !== null) {
                $this->db->executeCommand('SREM', [$this->getRuleItemsKey($currentRuleGuid), $guid]);
            }
            if ($ruleGuid !== null) {
                $this->db->executeCommand('SADD', [$this->getRuleItemsKey($ruleGuid), $guid]);
            }
            if ($ruleGuid !== null) {
                $updateItem[] = 'ruleGuid';
                $updateItem[] = $ruleGuid;
            } else {
                $updateEmptyItem[] = 'ruleGuid';
            }
        }
        if ($item->type !== $currentType) {
            $this->db->executeCommand('SREM', [$this->getTypeItemsKey($currentType), $guid]);
            $this->db->executeCommand('SADD', [$this->getTypeItemsKey($item->type), $guid]);
        }

        // update item
        $this->db->executeCommand('HMSET', $updateItem);
        // remove useless props
        if (count($updateEmptyItem) > 1) {
            $this->db->executeCommand('HDEL', $updateEmptyItem);
        }


        $this->db->executeCommand('EXEC');
        return true;

    }

    /**
     * @param integer $type item type
     * @return Item[]
     * @since XXX
     */
    public function getItems($type)
    {
        $itemGuids = $this->db->executeCommand('SMEMBERS', [$this->getTypeItemsKey($type)]);
        array_unshift($itemGuids, $this->getItemMappingGuidKey());
        $itemNames = $this->db->executeCommand('HMGET', $itemGuids);
        $items = [];
        foreach($itemNames as $itemName) {
            $items[$itemName] = $this->getItem($itemName);
        }
        return $items;
    }

    /**
     * @param Item $parent parent item
     * @param Item $child child item
     * @return bool
     * @since XXX
     */
    public function addChild(Item $parent, Item $child)
    {
        if ($parent->name === $child->name) {
            throw new InvalidParamException("Cannot add '{$parent->name}' as a child of itself.");
        }
        if ($parent instanceof Permission && $child instanceof Role) {
            throw new InvalidParamException('Cannot add a role as a child of a permission.');
        }
        if ($this->detectLoop($parent, $child)) {
            throw new InvalidCallException("Cannot add '{$child->name}' as a child of '{$parent->name}'. A loop has been detected.");
        }

        list($parentGuid, $childGuid) = $this->db->executeCommand('HMGET', [$this->getItemMappingKey(), $parent->name, $child->name]);

        $this->db->executeCommand('MULTI');
        $this->db->executeCommand('SADD', [$this->getItemParentsKey($childGuid), $parentGuid]);
        $this->db->executeCommand('SADD', [$this->getItemChildrenKey($parentGuid), $childGuid]);
        $this->db->executeCommand('EXEC');
        return true;
    }

    /**
     * @param string $name
     * @return Item[]
     * @since XXX
     */
    public function getChildren($name)
    {
        $guid = $this->db->executeCommand('HGET', [$this->getItemMappingKey(), $name]);
        $childrenGuids = $this->db->executeCommand('SMEMBERS', [$this->getItemChildrenKey($guid)]);
        $children = [];
        if (count($childrenGuids) > 0) {
            array_unshift($childrenGuids, $this->getItemMappingGuidKey());
            $childrenNames = $this->db->executeCommand('HMGET', $childrenGuids);
            foreach($childrenNames as $childName) {
                $children[] = $this->getItem($childName);
            }
        }
        return $children;
    }

    /**
     * @param Item $parent parent item
     * @return bool
     * @since XXX
     */
    public function removeChildren(Item $parent)
    {
        $guid = $this->db->executeCommand('HGET', [$this->getItemMappingKey(), $parent->name]);
        $childrenGuids = $this->db->executeCommand('SMEMBERS', [$this->getItemChildrenKey($guid)]);

        $this->db->executeCommand('MULTI');
        foreach($childrenGuids as $childGuid)
        {
            $this->db->executeCommand('SREM', [$this->getItemParentsKey($childGuid), $guid]);
        }
        $this->db->executeCommand('DEL', [$this->getItemChildrenKey($guid)]);
        $this->db->executeCommand('EXEC');
        return true;
    }

    /**
     * @param Item $parent parent item
     * @param Item $child child item
     * @return bool
     * @since XXX
     */
    public function removeChild(Item $parent, Item $child)
    {
        list($parentGuid, $childGuid) = $this->db->executeCommand('HMGET', [$this->getItemMappingKey(), $parent->name, $child->name]);
        $this->db->executeCommand('MULTI');
        $this->db->executeCommand('SREM', [$this->getItemParentsKey($childGuid), $parentGuid]);
        $this->db->executeCommand('SREM', [$this->getItemChildrenKey($parentGuid), $childGuid]);
        $this->db->executeCommand('EXEC');
        return true;
    }

    /**
     * @param Item $parent parent item
     * @param Item $child child item
     * @return bool
     * @since XXX
     */
    public function hasChild(Item $parent, Item $child)
    {
        list($parentGuid, $childGuid) = $this->db->executeCommand('HMGET', [$this->getItemMappingKey(), $parent->name, $child->name]);
        $result = (int)$this->db->executeCommand('SISMEMBER', [$this->getItemChildrenKey($parentGuid), $childGuid]);

        return $result === 1;
    }

    /**
     * @param Item $parent parent item
     * @param Item $child child item
     * @return bool
     * @since XXX
     */
    public function canAddChild(Item $parent, Item $child)
    {
        return !$this->detectLoop($parent, $child);
    }

    /**
     * @param Item $parent parent item
     * @param Item $child child item
     * @return bool
     * @since XXX
     */
    protected function detectLoop(Item $parent, Item $child)
    {
        if ($child->name === $parent->name) {
            return true;
        }
        foreach ($this->getChildren($child->name) as $grandchild) {
            if ($this->detectLoop($parent, $grandchild)) {
                return true;
            }
        }
        return false;
    }

    public function assign(Item $role, $userId)
    {
        $assignment = new Assignment([
            'userId' => $userId,
            'roleName' => $role->name,
            'createdAt' => time(),
        ]);
        $roleGuid = $this->db->executeCommand('HGET', [$this->getItemMappingKey(), $role->name]);

        $this->db->executeCommand('MULTI');
        $this->db->executeCommand('ZADD', [$this->getUserAssignmentsKey($userId), $assignment->createdAt, $roleGuid]);
        $this->db->executeCommand('ZADD', [$this->getRoleAssignmentsKey($roleGuid), $assignment->createdAt, $userId]);
        $this->db->executeCommand('EXEC');
        return $assignment;
    }

    public function revoke(Item $role, $userId)
    {
        if (empty($userId) === true) {
            return false;
        }
        $roleGuid = $this->db->executeCommand('HGET', [$this->getItemMappingKey(), $role->name]);

        $this->db->executeCommand('MULTI');
        $this->db->executeCommand('ZREM', [$this->getUserAssignmentsKey($userId), $roleGuid]);
        $this->db->executeCommand('ZREM', [$this->getRoleAssignmentsKey($roleGuid), $userId]);
        $this->db->executeCommand('EXEC');
        return true;
    }

    public function revokeAll($userId)
    {
        if (empty($userId) === true) {
            return false;
        }
        $roleGuids = $this->db->executeCommand('ZRANGEBYSCORE', [$this->getUserAssignmentsKey($userId), '-inf', '+ing']);
        $this->db->executeCommand('MULTI');
        if (count($roleGuids) > 0) {
            foreach ($roleGuids as $roleGuid) {
                $this->db->executeCommand('ZREM', [$this->getRoleAssignmentsKey($roleGuid), $userId]);
            }
        }
        $this->db->executeCommand('DEL', [$this->getUserAssignmentsKey($userId)]);
        $this->db->executeCommand('EXEC');
        return true;
    }

    public function removeAll()
    {
        //TODO: KEYS is a performance killer. shoulb be replaced with some scan
        $keys = Yii::$app->redis->executeCommand('KEYS', ['auth:*']);
        if (count($keys) > 0) {
            Yii::$app->redis->executeCommand('DEL', $keys);
        }
    }

    public function removeAllPermissions()
    {
        $this->removeAllItems(Item::TYPE_PERMISSION);
    }

    public function removeAllRoles()
    {
        $this->removeAllItems(Item::TYPE_ROLE);
    }

    public function removeAllRules()
    {
        $rules = $this->getRules();
        foreach($rules as $rule) {
            $this->removeRule($rule);
        }
    }

    public function removeAllAssignments()
    {
        $roleAssignKey = $this->getRoleAssignmentsKey('*');
        $userAssignKey = $this->getUserAssignmentsKey('*');
        $assignmentKeys = [];

        $nextCursor = 0;
        do {
            list($nextCursor, $keys) = $this->db->executeCommand('SCAN', [$nextCursor, 'MATCH', $roleAssignKey]);
            $assignmentKeys = array_merge($assignmentKeys, $keys);

        } while($nextCursor != 0);

        $nextCursor = 0;
        do {
            list($nextCursor, $keys) = $this->db->executeCommand('SCAN', [$nextCursor, 'MATCH', $userAssignKey]);
            $assignmentKeys = array_merge($assignmentKeys, $keys);

        } while($nextCursor != 0);

        if (count($assignmentKeys) > 0) {
            $this->db->executeCommand('DEL', $assignmentKeys);
        }
    }

    public function removeAllItems($type)
    {
        $items = $this->getItems($type);
        foreach ($items as $item) {
            $this->removeItem($item);
        }
    }

    /**
     * @inheritdoc
     */
    public function getRolesByUser($userId)
    {
        if (!isset($userId) || $userId === '') {
            return [];
        }

        $roleGuids = $this->db->executeCommand('ZRANGEBYSCORE', [$this->getUserAssignmentsKey($userId), '-inf', '+inf']);

        $roles = [];
        if (count($roleGuids) > 0) {
            array_unshift($roleGuids, $this->getItemMappingGuidKey());
            $roleNames = $this->db->executeCommand ('HMGET', $roleGuids);
            foreach ($roleNames as $roleName) {
                $roles[$roleName] = $this->getItem($roleName);
            }
        }

        return $roles;
    }

    /**
     * Returns all role assignment information for the specified role.
     * @param string $roleName
     * @return Assignment[] the assignments. An empty array will be
     * returned if role is not assigned to any user.
     * @since 2.0.7
     */
    public function getUserIdsByRole($roleName)
    {
        if (empty($roleName)) {
            return [];
        }
        $roleGuid = $this->db->executeCommand('HGET', [$this->getItemMappingKey(), $roleName]);
        $userIds = [];
        if ($roleGuid !== null) {
            $userIds = $this->db->executeCommand('ZRANGEBYSCORE', [$this->getRoleAssignmentsKey($roleGuid), '-inf', '+inf']);
        }
        return $userIds;
    }




    /**
     * @param array $dataRow
     * @return Permission|Role
     * @since XXX
     */
    protected function populateItem($dataRow)
    {
        $class = $dataRow['type'] == Item::TYPE_PERMISSION ? Permission::className() : Role::className();
        if (!isset($dataRow['data']) || ($data = @unserialize($dataRow['data'])) === false) {
            $data = null;
        }

        return new $class([
            'name' => $dataRow['name'],
            'type' => $dataRow['type'],
            'description' => isset($dataRow['description']) ? $dataRow['description'] : null,
            'ruleName' => isset($dataRow['ruleName']) ? $dataRow['ruleName'] : null,
            'data' => $data,
            'createdAt' => $dataRow['createdAt'],
            'updatedAt' => $dataRow['updatedAt'],
        ]);
    }
}
