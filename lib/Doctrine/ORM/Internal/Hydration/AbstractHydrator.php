<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\ORM\Internal\Hydration;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\AssociationMetadata;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ToOneAssociationMetadata;
use Doctrine\ORM\Utility\IdentifierFlattener;
use PDO;

/**
 * Base class for all hydrators. A hydrator is a class that provides some form
 * of transformation of an SQL result set into another structure.
 *
 * @since  2.0
 * @author Konsta Vesterinen <kvesteri@cc.hut.fi>
 * @author Roman Borschel <roman@code-factory.org>
 * @author Guilherme Blanco <guilhermeblanoc@hotmail.com>
 */
abstract class AbstractHydrator
{
    /**
     * The ResultSetMapping.
     *
     * @var \Doctrine\ORM\Query\ResultSetMapping
     */
    protected $rsm;

    /**
     * The EntityManager instance.
     *
     * @var EntityManagerInterface
     */
    protected $em;

    /**
     * The dbms Platform instance.
     *
     * @var \Doctrine\DBAL\Platforms\AbstractPlatform
     */
    protected $platform;

    /**
     * The UnitOfWork of the associated EntityManager.
     *
     * @var \Doctrine\ORM\UnitOfWork
     */
    protected $uow;

    /**
     * The IdentifierFlattener used for manipulating identifiers
     *
     * @var \Doctrine\ORM\Utility\IdentifierFlattener
     */
    protected $identifierFlattener;

    /**
     * Local ClassMetadata cache to avoid going to the EntityManager all the time.
     *
     * @var array
     */
    protected $metadataCache = [];

    /**
     * The cache used during row-by-row hydration.
     *
     * @var array
     */
    protected $cache = [];

    /**
     * The statement that provides the data to hydrate.
     *
     * @var \Doctrine\DBAL\Driver\Statement
     */
    protected $stmt;

    /**
     * The query hints.
     *
     * @var array
     */
    protected $hints;

    /**
     * Initializes a new instance of a class derived from <tt>AbstractHydrator</tt>.
     *
     * @param EntityManagerInterface $em The EntityManager to use.
     */
    public function __construct(EntityManagerInterface $em)
    {
        $this->em                 = $em;
        $this->platform           = $em->getConnection()->getDatabasePlatform();
        $this->uow                = $em->getUnitOfWork();
        $this->identifierFlattener = new IdentifierFlattener($this->uow, $em->getMetadataFactory());
    }

    /**
     * Initiates a row-by-row hydration.
     *
     * @param object $stmt
     * @param object $resultSetMapping
     * @param array  $hints
     *
     * @return IterableResult
     */
    public function iterate($stmt, $resultSetMapping, array $hints = [])
    {
        $this->stmt  = $stmt;
        $this->rsm   = $resultSetMapping;
        $this->hints = $hints;

        $evm = $this->em->getEventManager();

        $evm->addEventListener([Events::onClear], $this);

        $this->prepare();

        return new IterableResult($this);
    }

    /**
     * Hydrates all rows returned by the passed statement instance at once.
     *
     * @param object $stmt
     * @param object $resultSetMapping
     * @param array  $hints
     *
     * @return array
     */
    public function hydrateAll($stmt, $resultSetMapping, array $hints = [])
    {
        $this->stmt  = $stmt;
        $this->rsm   = $resultSetMapping;
        $this->hints = $hints;

        $this->em->getEventManager()->addEventListener([Events::onClear], $this);

        $this->prepare();

        $result = $this->hydrateAllData();

        $this->cleanup();

        return $result;
    }

    /**
     * Hydrates a single row returned by the current statement instance during
     * row-by-row hydration with {@link iterate()}.
     *
     * @return mixed
     */
    public function hydrateRow()
    {
        $row = $this->stmt->fetch(PDO::FETCH_ASSOC);

        if ( ! $row) {
            $this->cleanup();

            return false;
        }

        $result = [];

        $this->hydrateRowData($row, $result);

        return $result;
    }

    /**
     * When executed in a hydrate() loop we have to clear internal state to
     * decrease memory consumption.
     *
     * @param mixed $eventArgs
     *
     * @return void
     */
    public function onClear($eventArgs)
    {
    }

    /**
     * Executes one-time preparation tasks, once each time hydration is started
     * through {@link hydrateAll} or {@link iterate()}.
     *
     * @return void
     */
    protected function prepare()
    {
    }

    /**
     * Executes one-time cleanup tasks at the end of a hydration that was initiated
     * through {@link hydrateAll} or {@link iterate()}.
     *
     * @return void
     */
    protected function cleanup()
    {
        $this->stmt->closeCursor();

        $this->stmt          = null;
        $this->rsm           = null;
        $this->cache         = [];
        $this->metadataCache = [];

        $this->em
             ->getEventManager()
             ->removeEventListener([Events::onClear], $this);
    }

    /**
     * Hydrates a single row from the current statement instance.
     *
     * Template method.
     *
     * @param array $data   The row data.
     * @param array $result The result to fill.
     *
     * @return void
     *
     * @throws HydrationException
     */
    protected function hydrateRowData(array $data, array &$result)
    {
        throw new HydrationException("hydrateRowData() not implemented by this hydrator.");
    }

    /**
     * Hydrates all rows from the current statement instance at once.
     *
     * @return array
     */
    abstract protected function hydrateAllData();

    /**
     * Processes a row of the result set.
     *
     * Used for identity-based hydration (HYDRATE_OBJECT and HYDRATE_ARRAY).
     * Puts the elements of a result row into a new array, grouped by the dql alias
     * they belong to. The column names in the result set are mapped to their
     * field names during this procedure as well as any necessary conversions on
     * the values applied. Scalar values are kept in a specific key 'scalars'.
     *
     * @param array  $data               SQL Result Row.
     * @param array &$id                 Dql-Alias => ID-Hash.
     * @param array &$nonemptyComponents Does this DQL-Alias has at least one non NULL value?
     *
     * @return array  An array with all the fields (name => value) of the data row,
     *                grouped by their component alias.
     */
    protected function gatherRowData(array $data, array &$id, array &$nonemptyComponents)
    {
        $rowData = ['data' => []];

        foreach ($data as $key => $value) {
            if (($cacheKeyInfo = $this->hydrateColumnInfo($key)) === null) {
                continue;
            }

            $fieldName = $cacheKeyInfo['fieldName'];

            switch (true) {
                case (isset($cacheKeyInfo['isNewObjectParameter'])):
                    $argIndex = $cacheKeyInfo['argIndex'];
                    $objIndex = $cacheKeyInfo['objIndex'];
                    $type     = $cacheKeyInfo['type'];
                    $value    = $type->convertToPHPValue($value, $this->platform);

                    $rowData['newObjects'][$objIndex]['class']           = $cacheKeyInfo['class'];
                    $rowData['newObjects'][$objIndex]['args'][$argIndex] = $value;
                    break;

                case (isset($cacheKeyInfo['isScalar'])):
                    $type  = $cacheKeyInfo['type'];
                    $value = $type->convertToPHPValue($value, $this->platform);

                    $rowData['scalars'][$fieldName] = $value;
                    break;

                //case (isset($cacheKeyInfo['isMetaColumn'])):
                default:
                    $dqlAlias = $cacheKeyInfo['dqlAlias'];
                    $type     = $cacheKeyInfo['type'];

                    // If there are field name collisions in the child class, then we need
                    // to only hydrate if we are looking at the correct discriminator value
                    if(
                        isset($cacheKeyInfo['discriminatorColumn']) &&
                        isset($data[$cacheKeyInfo['discriminatorColumn']]) &&
                        // Note: loose comparison required. See https://github.com/doctrine/doctrine2/pull/6304#issuecomment-323294442
                        $data[$cacheKeyInfo['discriminatorColumn']] != $cacheKeyInfo['discriminatorValue']
                    ) {
                        break;
                    }

                    // in an inheritance hierarchy the same field could be defined several times.
                    // We overwrite this value so long we don't have a non-null value, that value we keep.
                    // Per definition it cannot be that a field is defined several times and has several values.
                    if (isset($rowData['data'][$dqlAlias][$fieldName])) {
                        break;
                    }

                    $rowData['data'][$dqlAlias][$fieldName] = $type
                        ? $type->convertToPHPValue($value, $this->platform)
                        : $value;

                    if ($cacheKeyInfo['isIdentifier'] && $value !== null) {
                        $id[$dqlAlias] .= '|' . $value;
                        $nonemptyComponents[$dqlAlias] = true;
                    }
                    break;
            }
        }

        return $rowData;
    }

    /**
     * Processes a row of the result set.
     *
     * Used for HYDRATE_SCALAR. This is a variant of _gatherRowData() that
     * simply converts column names to field names and properly converts the
     * values according to their types. The resulting row has the same number
     * of elements as before.
     *
     * @param array $data
     *
     * @return array The processed row.
     */
    protected function gatherScalarRowData(&$data)
    {
        $rowData = [];

        foreach ($data as $key => $value) {
            if (($cacheKeyInfo = $this->hydrateColumnInfo($key)) === null) {
                continue;
            }

            $fieldName = $cacheKeyInfo['fieldName'];

            // WARNING: BC break! We know this is the desired behavior to type convert values, but this
            // erroneous behavior exists since 2.0 and we're forced to keep compatibility.
            if ( ! isset($cacheKeyInfo['isScalar'])) {
                $dqlAlias  = $cacheKeyInfo['dqlAlias'];
                $type      = $cacheKeyInfo['type'];
                $fieldName = $dqlAlias . '_' . $fieldName;
                $value     = $type
                    ? $type->convertToPHPValue($value, $this->platform)
                    : $value;
            }

            $rowData[$fieldName] = $value;
        }

        return $rowData;
    }

    /**
     * Retrieve column information from ResultSetMapping.
     *
     * @param string $key Column name
     *
     * @return array|null
     */
    protected function hydrateColumnInfo($key)
    {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        switch (true) {
            // NOTE: Most of the times it's a field mapping, so keep it first!!!
            case (isset($this->rsm->fieldMappings[$key])):
                $classMetadata = $this->getClassMetadata($this->rsm->declaringClasses[$key]);
                $fieldName     = $this->rsm->fieldMappings[$key];
                $ownerMap      = $this->rsm->columnOwnerMap[$key];
                $property      = $classMetadata->getProperty($fieldName);

                $columnInfo = [
                    'isIdentifier' => $property->isPrimaryKey(),
                    'fieldName'    => $fieldName,
                    'type'         => $property->getType(),
                    'dqlAlias'     => $this->rsm->columnOwnerMap[$key],
                ];

                // the current discriminator value must be saved in order to disambiguate fields hydration,
                // should there be field name collisions
                if ($classMetadata->parentClasses && isset($this->rsm->discriminatorColumns[$ownerMap])) {
                    return $this->cache[$key] = \array_merge(
                        $columnInfo,
                        [
                            'discriminatorColumn' => $this->rsm->discriminatorColumns[$ownerMap],
                            'discriminatorValue'  => $classMetadata->discriminatorValue
                        ]
                    );
                }

                return $this->cache[$key] = $columnInfo;

            case (isset($this->rsm->newObjectMappings[$key])):
                // WARNING: A NEW object is also a scalar, so it must be declared before!
                $mapping = $this->rsm->newObjectMappings[$key];

                return $this->cache[$key] = [
                    'isScalar'             => true,
                    'isNewObjectParameter' => true,
                    'fieldName'            => $this->rsm->scalarMappings[$key],
                    'type'                 => $this->rsm->typeMappings[$key],
                    'argIndex'             => $mapping['argIndex'],
                    'objIndex'             => $mapping['objIndex'],
                    'class'                => new \ReflectionClass($mapping['className']),
                ];

            case (isset($this->rsm->scalarMappings[$key])):
                return $this->cache[$key] = [
                    'isScalar'  => true,
                    'fieldName' => $this->rsm->scalarMappings[$key],
                    'type'      => $this->rsm->typeMappings[$key],
                ];

            case (isset($this->rsm->metaMappings[$key])):
                // Meta column (has meaning in relational schema only, i.e. foreign keys or discriminator columns).
                $fieldName = $this->rsm->metaMappings[$key];
                $dqlAlias  = $this->rsm->columnOwnerMap[$key];

                // Cache metadata fetch
                $this->getClassMetadata($this->rsm->aliasMap[$dqlAlias]);

                return $this->cache[$key] = [
                    'isIdentifier' => isset($this->rsm->isIdentifierColumn[$dqlAlias][$key]),
                    'isMetaColumn' => true,
                    'fieldName'    => $fieldName,
                    'type'         => $this->rsm->typeMappings[$key],
                    'dqlAlias'     => $dqlAlias,
                ];
        }

        // this column is a left over, maybe from a LIMIT query hack for example in Oracle or DB2
        // maybe from an additional column that has not been defined in a NativeQuery ResultSetMapping.
        return null;
    }

    /**
     * Retrieve ClassMetadata associated to entity class name.
     *
     * @param string $className
     *
     * @return \Doctrine\ORM\Mapping\ClassMetadata
     */
    protected function getClassMetadata($className)
    {
        if ( ! isset($this->metadataCache[$className])) {
            $this->metadataCache[$className] = $this->em->getClassMetadata($className);
        }

        return $this->metadataCache[$className];
    }

    /**
     * Register entity as managed in UnitOfWork.
     *
     * @param ClassMetadata $class
     * @param object        $entity
     * @param array         $data
     *
     * @return void
     */
    protected function registerManaged(ClassMetadata $class, $entity, array $data)
    {
        // TODO: All of the following share similar logic, consider refactoring: AbstractHydrator::registerManaged,
        // ObjectHydrator::getEntityFromIdentityMap and UnitOfWork::createEntity().
        // $id = $this->identifierFlattener->flattenIdentifier($class, $data);
        $id = [];

        foreach ($class->identifier as $fieldName) {
            $property = $class->getProperty($fieldName);

            if (! ($property instanceof ToOneAssociationMetadata)) {
                $id[$fieldName] = $data[$fieldName];

                continue;
            }

            $joinColumns = $property->getJoinColumns();
            $joinColumn  = reset($joinColumns);

            $id[$fieldName] = $data[$joinColumn->getColumnName()];
        }

        $this->em->getUnitOfWork()->registerManaged($entity, $id, $data);
    }
}
