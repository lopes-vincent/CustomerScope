<?php

namespace CustomerScope\Handler;

use CustomerScope\CustomerScope as CustomerscopeModule;
use CustomerScope\Model\CustomerQuery;
use CustomerScope\Model\CustomerScope;
use CustomerScope\Model\CustomerScopeQuery;
use CustomerScope\Model\Scope;
use CustomerScope\Model\ScopeQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Map\ColumnMap;
use Symfony\Component\DependencyInjection\ContainerAware;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Translation\Translator;

/**
 * Handler service for customer scopes.
 */
class CustomerScopeHandler extends ContainerAware
{
    protected $sessionScope;
    /** @var SecurityContext */
    protected $securityContext;
    /** @var Translator */
    protected $translator;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->securityContext = $this->container->get('thelia.securityContext');
        $this->translator = $this->container->get('thelia.translator');
    }

    /**
     * Create a customer scope by associating a customer to a scope entity.
     *
     * @param int $customerId Customer id.
     * @param mixed $entity Scope entity.
     *
     * @throws PropelException
     */
    public function registerCustomerScope($customerId, $entity)
    {
        $scope = $this->getScopeByEntity($entity);
        CustomerScopeQuery::create()
            ->filterByCustomerId($customerId)
            ->filterByScope($scope)
            ->filterByScopeEntity($scope->getEntity())
            ->filterByEntityId($entity->getId())
            ->findOneOrCreate()
            ->save();
    }


    /**
     * Get all child (recusively) of a defined type for an entity
     *
     * @param mixed $parentEntity
     * @param mixed $childType
     * @param boolean $toArray
     * @return mixed
     */
    public function getChildsOfType($parentEntity, $childType, $toArray = false)
    {
        $parentScope = $this->getScopeByEntity($parentEntity);
        $childScope = $this->getScopeByType($childType);

        if ($parentScope === null || $childScope === null) {
            return null;
        }

        //Get all the scopes between parent and child
        $scopes = $this->getScopePath($parentScope, $childScope);

        $childScopeQueryClass = $scopes[0]['EntityClass'] . "Query";
        /** @var ModelCriteria $childScopeQuery */
        $childScopeQuery = new $childScopeQueryClass;

        //Build array for propel relation
        $propelNames = [];
        foreach ($scopes as $key => $scope) {
            $propelNames[$key] = $this->altCamelize($scope['Entity']);
        }

        //Add join for each parent
        for ($i = 1; $i < count($scopes); $i++) {
            $childScopeQuery->join($propelNames[$i - 1] . "." . $propelNames[$i]);
        }

        //Find by top parent id
        $childScopeQuery->where(end($propelNames) . ".id = ?", $parentEntity->getId());

        if ($toArray === true) {
            return $childScopeQuery->find()->toArray();
        }

        return $childScopeQuery->find();
    }

    /**
     * Get the scope path from scope to another scope
     *
     * This method return an array of all scope entity
     * between a parent and child of a same scope group
     * (sorted from child to parent)
     *
     * @param Scope $parentScope
     * @param Scope $childScope
     * @return array
     */
    public function getScopePath(Scope $parentScope, Scope $childScope)
    {
        return ScopeQuery::create()
            ->filterByScopeGroupId($parentScope->getScopeGroupId())
            ->filterByPosition($parentScope->getPosition(), Criteria::GREATER_EQUAL)
            ->filterByPosition($childScope->getPosition(), Criteria::LESS_EQUAL)
            ->orderByPosition(Criteria::DESC)
            ->find()->toArray();
    }

    /**
     * Get the first customer scope for the logged customer or a specified customer.
     *
     * @param int|null $customerId Customer id
     * @return mixed object of a scope entity, or null if none was found
     */
    public function getCustomerScopeEntity($customerId = null)
    {
        if ($customerId === null && $this->securityContext->getCustomerUser() !== null) {
            $customerId = $this->getLoggedCustomerId();
        }

        if (null === $customerScope = CustomerScopeQuery::create()->findOneByCustomerId($customerId)) {
            return null;
        }

        return $this->getEntityByScope($customerScope->getScope(), $customerScope->getEntityId());
    }

    /**
     * Get all the entities of a defined type for a customer (or logged customer)
     *
     * @param $scopeType
     * @param null $customerId
     * @return array
     */
    public function getAllCustomerScopeEntitiesByType($scopeType, $customerId = null)
    {
        $customerEntities = $this->getCustomerScopeEntities($customerId);
        $entities = [];

        foreach ($customerEntities as $customerEntity) {
            $entities = array_merge($this->getChildsOfType($customerEntity, $scopeType, true), $entities);
        }

        return $entities;
    }

    /**
     * Get all customerscopes for logged customer or specified customer if a customer id is passed in parameter
     *
     * @param int|null $customerId
     * @return array an array of all scope entities
     */
    public function getCustomerScopeEntities($customerId = null)
    {
        if ($customerId === null && $this->securityContext->getCustomerUser() !== null) {
            $customerId = $this->getLoggedCustomerId();
        }

        $customerScopes = CustomerScopeQuery::create()->findByCustomerId($customerId);

        $entities = [];

        /** @var CustomerScope $customerScope */
        foreach ($customerScopes as $customerScope) {
            $entities[] = $this->getEntityByScope($customerScope->getScope(), $customerScope->getEntityId());
        }

        return $entities;
    }

    /**
     * Return the direct parent entity of an entity
     *
     * @param $entity object an entity
     * @return object parent entity
     */
    public function getParent($entity)
    {
        $scope = $this->getScopeByEntity($entity);

        return $this->getParentScopeEntity($scope, $entity->getId());
    }

    /**
     * Return all the direct childs entities (in array) of an entity
     *
     * @param $entity object an entity
     * @return array an array of childs entity
     */
    public function getChilds($entity)
    {
        $scope = $this->getScopeByEntity($entity);

        return $this->getChildsScopeEntities($scope, $entity->getId());
    }


    /**
     * Get the direct parent entity of another entity by his Scope and id
     *
     * @param Scope $scope
     * @param $entityId
     * @return object parent entity or false if no parent found
     */
    public function getParentScopeEntity(Scope $scope, $entityId)
    {
        $parentScope = ScopeQuery::create()
            ->filterByScopeGroupId($scope->getScopeGroupId())
            ->filterByPosition($scope->getPosition(), Criteria::LESS_THAN)
            ->orderByPosition(Criteria::DESC)
            ->find()->getFirst();

        $this->getEntityQueryByScope($parentScope);

        $scopeEntityQuery = $this->getEntityQueryByScope($scope);
        $scopeEntity = $this->getEntityByScope($scope, $entityId);

        /** @var ColumnMap $scopeForeignKey */
        foreach ($scopeEntityQuery->getTableMap()->getForeignKeys() as $scopeForeignKey) {
            if ($scopeForeignKey->getRelatedTableName() === $parentScope->getEntity()) {
                $fkValue = $scopeEntity->getByName($scopeForeignKey->getPhpName());
                $parentEntity = $this->getEntityByScope($parentScope, $fkValue);
            }
        }

        if (!isset($parentEntity)) {
            return false;
        }

        return $parentEntity;
    }

    /**
     * Get an array of all direct childs entities for another entity by his Scope and id
     *
     * @param Scope $scope
     * @param $entityId
     * @return array an array of childs entities (ex : Store)
     */
    public function getChildsScopeEntities(Scope $scope, $entityId)
    {
        $childScope = ScopeQuery::create()
            ->filterByScopeGroupId($scope->getScopeGroupId())
            ->filterByPosition($scope->getPosition(), Criteria::GREATER_THAN)
            ->orderByPosition(Criteria::ASC)
            ->find()->getFirst();

        if (null === $childScope) {
            return null;
        }

        $childScopeEntityQuery = $this->getEntityQueryByScope($childScope);

        $fkName = null;
        foreach ($childScopeEntityQuery->getTableMap()->getForeignKeys() as $childForeignKey) {
            if ($childForeignKey->getRelatedTableName() === $scope->getEntity()) {
                $fkName = $childForeignKey->getPhpName();
            }
        }

        $childsEntities = $childScopeEntityQuery->findBy($fkName, $entityId);

        $childsEntitiesArray = [];

        foreach ($childsEntities as $childsEntity) {
            $childsEntitiesArray[] = $childsEntity;
        }

        return $childsEntitiesArray;
    }


    /**
     * Get the scope of an entity
     *
     * @param mixed $entity
     * @return Scope
     */
    public function getScopeByEntity($entity)
    {
        $entityTableMap = $entity::TABLE_MAP;
        $entityClassName = ltrim((new $entityTableMap)->getOMClass(false), '\\');

        return $scope = ScopeQuery::create()->findOneByEntityClass($entityClassName);
    }

    /**
     * Get the instance of entity for the scope and entityId given
     *
     * @param Scope $scope
     * @param int $scopeEntityId
     * @return mixed
     */
    public function getEntityByScope(Scope $scope, $scopeEntityId)
    {
        $scopeEntityQuery = $this->getEntityQueryByScope($scope);

        return $scopeEntityQuery->findOneById($scopeEntityId);
    }

    /**
     * Get the instance of entity for the type and entityId given
     *
     * @param string $scopeType
     * @param $scopeEntityId
     * @return mixed
     */
    public function getEntityByType($scopeType, $scopeEntityId)
    {
        $scope = $this->getScopeByType($scopeType);

        return $this->getEntityByScope($scope, $scopeEntityId);
    }


    /**
     * Get the Scope by his type
     *
     * @param string $scopeType The scope type (ex: store)
     * @return Scope
     */
    public function getScopeByType($scopeType)
    {
        return ScopeQuery::create()->findOneByEntity($scopeType);
    }

    /**
     * Get an instance of entity query for a specified scope
     *
     * @param Scope $scope
     * @return ModelCriteria
     */
    public function getEntityQueryByScope(Scope $scope)
    {
        $scopeEntityQueryClass = $scope->getEntityClass() . 'Query';

        return new $scopeEntityQueryClass;
    }

    /**
     * Get the id of logged customer or throw exception if no customer logged
     *
     * @return int id of logged customer
     * @throws \Exception if no customer is logged
     */
    public function getLoggedCustomerId()
    {
        $customerId = $this->securityContext->getCustomerUser()->getId();

        if ($customerId === null) {
            throw new \Exception(
                $this->translator->trans(
                    "Error on %s : if no one customer is logged on you have to pass a customerId in parameter",
                    ['%s' => 'getCustomerScope'],
                    CustomerscopeModule::MESSAGE_DOMAIN
                )
            );
        }

        return $customerId;
    }

    /**
     * Check if customer is on the current logged customer's scope
     *
     * @param int $customerId
     * @return bool
     */
    public function checkScopeByCustomerId($customerId)
    {
        $query = new CustomerQuery();

        /** @var Request $request */
        $request = $this->container->get('request');
        $sessionScopes = $request->getSession()->get(CustomerScopeModule::getModuleCode());

        if ($sessionScopes) {
            $query->filterByScopes($sessionScopes);
        }

        return ($query->findOneById($customerId)) ? true : false;
    }

    /**
     * Camelize an entity string to fit with propel relation name
     * @param string $scored
     * @return string
     */
    protected function altCamelize($scored)
    {
        return ucfirst(
            implode(
                '',
                array_map(
                    'ucfirst',
                    array_map(
                        'strtolower',
                        explode(
                            '_',
                            $scored
                        )
                    )
                )
            )
        );
    }
}
