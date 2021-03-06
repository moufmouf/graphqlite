<?php


namespace TheCodingMachine\GraphQLite;

use function array_merge;
use function get_parent_class;
use GraphQL\Type\Definition\InputType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\OutputType;
use GraphQL\Type\Definition\WrappingType;
use GraphQL\Upload\UploadType;
use phpDocumentor\Reflection\Fqsen;
use phpDocumentor\Reflection\Types\Nullable;
use phpDocumentor\Reflection\Types\Self_;
use Psr\Http\Message\UploadedFileInterface;
use ReflectionMethod;
use function sprintf;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\SourceFieldInterface;
use TheCodingMachine\GraphQLite\Hydrators\HydratorInterface;
use TheCodingMachine\GraphQLite\Mappers\CannotMapTypeExceptionInterface;
use TheCodingMachine\GraphQLite\Mappers\Root\RootTypeMapperInterface;
use TheCodingMachine\GraphQLite\Reflection\CachedDocBlockFactory;
use TheCodingMachine\GraphQLite\Types\ArgumentResolver;
use TheCodingMachine\GraphQLite\Types\CustomTypesRegistry;
use TheCodingMachine\GraphQLite\Types\ID;
use TheCodingMachine\GraphQLite\Types\TypeResolver;
use TheCodingMachine\GraphQLite\Types\UnionType;
use Iterator;
use IteratorAggregate;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Tags\Return_;
use phpDocumentor\Reflection\Type;
use phpDocumentor\Reflection\Types\Array_;
use phpDocumentor\Reflection\Types\Boolean;
use phpDocumentor\Reflection\Types\Compound;
use phpDocumentor\Reflection\Types\Float_;
use phpDocumentor\Reflection\Types\Iterable_;
use phpDocumentor\Reflection\Types\Mixed_;
use phpDocumentor\Reflection\Types\Null_;
use phpDocumentor\Reflection\Types\Object_;
use phpDocumentor\Reflection\Types\String_;
use phpDocumentor\Reflection\Types\Integer;
use ReflectionClass;
use TheCodingMachine\GraphQLite\Annotations\SourceField;
use TheCodingMachine\GraphQLite\Annotations\Logged;
use TheCodingMachine\GraphQLite\Annotations\Mutation;
use TheCodingMachine\GraphQLite\Annotations\Query;
use TheCodingMachine\GraphQLite\Mappers\CannotMapTypeException;
use TheCodingMachine\GraphQLite\Mappers\RecursiveTypeMapperInterface;
use TheCodingMachine\GraphQLite\Reflection\CommentParser;
use TheCodingMachine\GraphQLite\Security\AuthenticationServiceInterface;
use TheCodingMachine\GraphQLite\Security\AuthorizationServiceInterface;
use TheCodingMachine\GraphQLite\Types\DateTimeType;
use GraphQL\Type\Definition\Type as GraphQLType;

/**
 * A class in charge if returning list of fields for queries / mutations / entities / input types
 */
class FieldsBuilder
{
    /**
     * @var AnnotationReader
     */
    private $annotationReader;
    /**
     * @var RecursiveTypeMapperInterface
     */
    private $typeMapper;
    /**
     * @var ArgumentResolver
     */
    private $argumentResolver;
    /**
     * @var AuthenticationServiceInterface
     */
    private $authenticationService;
    /**
     * @var AuthorizationServiceInterface
     */
    private $authorizationService;
    /**
     * @var CachedDocBlockFactory
     */
    private $cachedDocBlockFactory;
    /**
     * @var TypeResolver
     */
    private $typeResolver;
    /**
     * @var NamingStrategyInterface
     */
    private $namingStrategy;
    /**
     * @var RootTypeMapperInterface
     */
    private $rootTypeMapper;

    public function __construct(AnnotationReader $annotationReader, RecursiveTypeMapperInterface $typeMapper,
                                ArgumentResolver $argumentResolver, AuthenticationServiceInterface $authenticationService,
                                AuthorizationServiceInterface $authorizationService, TypeResolver $typeResolver,
                                CachedDocBlockFactory $cachedDocBlockFactory, NamingStrategyInterface $namingStrategy,
                                RootTypeMapperInterface $rootTypeMapper)
    {
        $this->annotationReader = $annotationReader;
        $this->typeMapper = $typeMapper;
        $this->argumentResolver = $argumentResolver;
        $this->authenticationService = $authenticationService;
        $this->authorizationService = $authorizationService;
        $this->typeResolver = $typeResolver;
        $this->cachedDocBlockFactory = $cachedDocBlockFactory;
        $this->namingStrategy = $namingStrategy;
        $this->rootTypeMapper = $rootTypeMapper;
    }

    // TODO: Add RecursiveTypeMapper in the list of parameters for getQueries and REMOVE the ControllerQueryProviderFactory.

    /**
     * @param object $controller
     * @return QueryField[]
     * @throws \ReflectionException
     */
    public function getQueries($controller): array
    {
        return $this->getFieldsByAnnotations($controller,Query::class, false);
    }

    /**
     * @param object $controller
     * @return QueryField[]
     * @throws \ReflectionException
     */
    public function getMutations($controller): array
    {
        return $this->getFieldsByAnnotations($controller,Mutation::class, false);
    }

    /**
     * @return array<string, QueryField> QueryField indexed by name.
     */
    public function getFields($controller): array
    {

        $fieldAnnotations = $this->getFieldsByAnnotations($controller, Annotations\Field::class, true);

        $refClass = new \ReflectionClass($controller);

        /** @var SourceField[] $sourceFields */
        $sourceFields = $this->annotationReader->getSourceFields($refClass);

        if ($controller instanceof FromSourceFieldsInterface) {
            $sourceFields = array_merge($sourceFields, $controller->getSourceFields());
        }

        $fieldsFromSourceFields = $this->getQueryFieldsFromSourceFields($sourceFields, $refClass);


        $fields = [];
        foreach ($fieldAnnotations as $field) {
            $fields[$field->name] = $field;
        }
        foreach ($fieldsFromSourceFields as $field) {
            $fields[$field->name] = $field;
        }

        return $fields;
    }

    /**
     * Track Field annotation in a self targeted type
     *
     * @return array<string, QueryField> QueryField indexed by name.
     */
    public function getSelfFields(string $className): array
    {
        $fieldAnnotations = $this->getFieldsByAnnotations(null, Annotations\Field::class, false, $className);

        $refClass = new \ReflectionClass($className);

        /** @var SourceField[] $sourceFields */
        $sourceFields = $this->annotationReader->getSourceFields($refClass);

        $fieldsFromSourceFields = $this->getQueryFieldsFromSourceFields($sourceFields, $refClass);

        $fields = [];
        foreach ($fieldAnnotations as $field) {
            $fields[$field->name] = $field;
        }
        foreach ($fieldsFromSourceFields as $field) {
            $fields[$field->name] = $field;
        }

        return $fields;
    }

    /**
     * @param ReflectionMethod $refMethod A method annotated with a Factory annotation.
     * @return array<string, array<int, mixed>> Returns an array of fields as accepted by the InputObjectType constructor.
     */
    public function getInputFields(ReflectionMethod $refMethod): array
    {
        $docBlockObj = $this->cachedDocBlockFactory->getDocBlock($refMethod);
        //$docBlockComment = $docBlockObj->getSummary()."\n".$docBlockObj->getDescription()->render();

        $parameters = $refMethod->getParameters();

        $args = $this->mapParameters($parameters, $docBlockObj, $refMethod);

        return $args;
    }

    /**
     * @param object $controller
     * @param string $annotationName
     * @param bool $injectSource Whether to inject the source object or not as the first argument. True for @Field (unless @Type has no class attribute), false for @Query and @Mutation
     * @return QueryField[]
     * @throws CannotMapTypeExceptionInterface
     * @throws \ReflectionException
     */
    private function getFieldsByAnnotations($controller, string $annotationName, bool $injectSource, ?string $sourceClassName = null): array
    {
        if ($sourceClassName !== null) {
            $refClass = new \ReflectionClass($sourceClassName);
        } else {
            $refClass = new \ReflectionClass($controller);
        }

        $queryList = [];

        $oldDeclaringClass = null;
        $context = null;

        $closestMatchingTypeClass = null;
        if ($annotationName === Field::class) {
            $parent = get_parent_class($refClass->getName());
            if ($parent !== null) {
                $closestMatchingTypeClass = $this->typeMapper->findClosestMatchingParent($parent);
            }
        }

        foreach ($refClass->getMethods() as $refMethod) {
            if ($closestMatchingTypeClass !== null && $closestMatchingTypeClass === $refMethod->getDeclaringClass()->getName()) {
                // Optimisation: no need to fetch annotations from parent classes that are ALREADY GraphQL types.
                // We will merge the fields anyway.
                break;
            }

            // First, let's check the "Query" or "Mutation" or "Field" annotation
            $queryAnnotation = $this->annotationReader->getRequestAnnotation($refMethod, $annotationName);

            if ($queryAnnotation !== null) {
                $unauthorized = false;
                if (!$this->isAuthorized($refMethod)) {
                    $failWith = $this->annotationReader->getFailWithAnnotation($refMethod);
                    if ($failWith === null) {
                        continue;
                    }
                    $unauthorized = true;
                }

                $docBlockObj = $this->cachedDocBlockFactory->getDocBlock($refMethod);
                $docBlockComment = $docBlockObj->getSummary()."\n".$docBlockObj->getDescription()->render();

                $methodName = $refMethod->getName();
                $name = $queryAnnotation->getName() ?: $this->namingStrategy->getFieldNameFromMethodName($methodName);

                $parameters = $refMethod->getParameters();
                if ($injectSource === true) {
                    $first_parameter = array_shift($parameters);
                    // TODO: check that $first_parameter type is correct.
                }

                $args = $this->mapParameters($parameters, $docBlockObj, $refMethod);

                if ($queryAnnotation->getOutputType()) {
                    try {
                        $type = $this->typeResolver->mapNameToOutputType($queryAnnotation->getOutputType());
                    } catch (CannotMapTypeExceptionInterface $e) {
                        throw CannotMapTypeException::wrapWithReturnInfo($e, $refMethod);
                    }
                } else {
                    $type = $this->mapReturnType($refMethod, $docBlockObj);
                }

                if ($unauthorized) {
                    $failWithValue = $failWith->getValue();
                    $queryList[] = QueryField::alwaysReturn($name, $type, $args, $failWithValue, $this->argumentResolver, $docBlockComment);
                } else {
                    if ($sourceClassName !== null) {
                        $queryList[] = QueryField::selfField($name, $type, $args, $methodName, $this->argumentResolver, $docBlockComment);
                    } else {
                        $queryList[] = QueryField::externalField($name, $type, $args, [$controller, $methodName], $this->argumentResolver, $docBlockComment, $injectSource);
                    }
                }
            }
        }

        return $queryList;
    }

    /**
     * @return GraphQLType&OutputType
     */
    private function mapReturnType(ReflectionMethod $refMethod, DocBlock $docBlockObj): GraphQLType
    {
        $returnType = $refMethod->getReturnType();
        if ($returnType !== null) {
            $typeResolver = new \phpDocumentor\Reflection\TypeResolver();
            $phpdocType = $typeResolver->resolve((string) $returnType);
            $phpdocType = $this->resolveSelf($phpdocType, $refMethod->getDeclaringClass());
        } else {
            $phpdocType = new Mixed_();
        }

        $docBlockReturnType = $this->getDocBlocReturnType($docBlockObj, $refMethod);

        try {
            /** @var GraphQLType&OutputType $type */
            $type = $this->mapType($phpdocType, $docBlockReturnType, $returnType ? $returnType->allowsNull() : false, false, $refMethod, $docBlockObj);
        } catch (TypeMappingException $e) {
            throw TypeMappingException::wrapWithReturnInfo($e, $refMethod);
        } catch (CannotMapTypeExceptionInterface $e) {
            throw CannotMapTypeException::wrapWithReturnInfo($e, $refMethod);
        }
        return $type;
    }

    private function getDocBlocReturnType(DocBlock $docBlock, \ReflectionMethod $refMethod): ?Type
    {
        /** @var Return_[] $returnTypeTags */
        $returnTypeTags = $docBlock->getTagsByName('return');
        if (count($returnTypeTags) > 1) {
            throw InvalidDocBlockException::tooManyReturnTags($refMethod);
        }
        $docBlockReturnType = null;
        if (isset($returnTypeTags[0])) {
            $docBlockReturnType = $returnTypeTags[0]->getType();
        }
        return $docBlockReturnType;
    }

    /**
     * @param array<int, SourceFieldInterface> $sourceFields
     * @return QueryField[]
     * @throws CannotMapTypeException
     * @throws CannotMapTypeExceptionInterface
     * @throws \ReflectionException
     */
    private function getQueryFieldsFromSourceFields(array $sourceFields, ReflectionClass $refClass): array
    {
        if (empty($sourceFields)) {
            return [];
        }

        $typeField = $this->annotationReader->getTypeAnnotation($refClass);
        $extendTypeField = $this->annotationReader->getExtendTypeAnnotation($refClass);

        if ($typeField !== null) {
            $objectClass = $typeField->getClass();
        } elseif ($extendTypeField !== null) {
            $objectClass = $extendTypeField->getClass();
        } else {
            throw MissingAnnotationException::missingTypeExceptionToUseSourceField();
        }

        $objectRefClass = new \ReflectionClass($objectClass);

        $oldDeclaringClass = null;
        $context = null;
        $queryList = [];

        foreach ($sourceFields as $sourceField) {
            // Ignore the field if we must be logged.
            $right = $sourceField->getRight();
            $unauthorized = false;
            if (($sourceField->isLogged() && !$this->authenticationService->isLogged())
                || ($right !== null && !$this->authorizationService->isAllowed($right->getName()))) {
                if (!$sourceField->canFailWith()) {
                    continue;
                } else {
                    $unauthorized = true;
                }
            }

            try {
                $refMethod = $this->getMethodFromPropertyName($objectRefClass, $sourceField->getName());
            } catch (FieldNotFoundException $e) {
                throw FieldNotFoundException::wrapWithCallerInfo($e, $refClass->getName());
            }

            $methodName = $refMethod->getName();


            $docBlockObj = $this->cachedDocBlockFactory->getDocBlock($refMethod);
            $docBlockComment = $docBlockObj->getSummary()."\n".$docBlockObj->getDescription()->render();


            $args = $this->mapParameters($refMethod->getParameters(), $docBlockObj, $refMethod);

            if ($sourceField->isId()) {
                $type = GraphQLType::id();
                if (!$refMethod->getReturnType()->allowsNull()) {
                    $type = GraphQLType::nonNull($type);
                }
            } elseif ($sourceField->getOutputType()) {
                try {
                    $type = $this->typeResolver->mapNameToOutputType($sourceField->getOutputType());
                } catch (CannotMapTypeExceptionInterface $e) {
                    throw CannotMapTypeException::wrapWithSourceField($e, $refClass, $sourceField);
                }
            } else {
                $type = $this->mapReturnType($refMethod, $docBlockObj);
            }

            if (!$unauthorized) {
                $queryList[] = QueryField::selfField($sourceField->getName(), $type, $args, $methodName, $this->argumentResolver, $docBlockComment);
            } else {
                $failWithValue = $sourceField->getFailWith();
                $queryList[] = QueryField::alwaysReturn($sourceField->getName(), $type, $args, $failWithValue, $this->argumentResolver, $docBlockComment);
            }
        }
        return $queryList;
    }

    private function getMethodFromPropertyName(\ReflectionClass $reflectionClass, string $propertyName): \ReflectionMethod
    {
        if ($reflectionClass->hasMethod($propertyName)) {
            $methodName = $propertyName;
        } else {
            $upperCasePropertyName = \ucfirst($propertyName);
            if ($reflectionClass->hasMethod('get'.$upperCasePropertyName)) {
                $methodName = 'get'.$upperCasePropertyName;
            } elseif ($reflectionClass->hasMethod('is'.$upperCasePropertyName)) {
                $methodName = 'is'.$upperCasePropertyName;
            } else {
                throw FieldNotFoundException::missingField($reflectionClass->getName(), $propertyName);
            }
        }

        return $reflectionClass->getMethod($methodName);
    }

    /**
     * Checks the @Logged and @Right annotations.
     *
     * @param \ReflectionMethod $reflectionMethod
     * @return bool
     */
    private function isAuthorized(\ReflectionMethod $reflectionMethod) : bool
    {
        $loggedAnnotation = $this->annotationReader->getLoggedAnnotation($reflectionMethod);

        if ($loggedAnnotation !== null && !$this->authenticationService->isLogged()) {
            return false;
        }


        $rightAnnotation = $this->annotationReader->getRightAnnotation($reflectionMethod);

        if ($rightAnnotation !== null && !$this->authorizationService->isAllowed($rightAnnotation->getName())) {
            return false;
        }

        return true;
    }

    /**
     * Note: there is a bug in $refMethod->allowsNull that forces us to use $standardRefMethod->allowsNull instead.
     *
     * @param \ReflectionParameter[] $refParameters
     * @return array[] An array of ['type'=>Type, 'defaultValue'=>val]
     * @throws MissingTypeHintException
     */
    private function mapParameters(array $refParameters, DocBlock $docBlock, ReflectionMethod $refMethod): array
    {
        $args = [];

        $typeResolver = new \phpDocumentor\Reflection\TypeResolver();

        $docBlockTypes = [];
        if (!empty($refParameters)) {
            /** @var DocBlock\Tags\Param[] $paramTags */
            $paramTags = $docBlock->getTagsByName('param');
            foreach ($paramTags as $paramTag) {
                $docBlockTypes[$paramTag->getVariableName()] = $paramTag->getType();
            }
        }

        foreach ($refParameters as $parameter) {
            $parameterType = $parameter->getType();
            $allowsNull = $parameterType === null ? true : $parameterType->allowsNull();

            $type = (string) $parameterType;
            if ($type === '') {
                $phpdocType = new Mixed_();
                $allowsNull = false;
                //throw MissingTypeHintException::missingTypeHint($parameter);
            } else {
                $phpdocType = $typeResolver->resolve($type);
                $phpdocType = $this->resolveSelf($phpdocType, $parameter->getDeclaringClass());
            }

            $docBlockType = $docBlockTypes[$parameter->getName()] ?? null;

            try {
                $arr = [
                    'type' => $this->mapType($phpdocType, $docBlockType, $allowsNull || $parameter->isDefaultValueAvailable(), true, $refMethod, $docBlock, $parameter->getName()),
                ];
            } catch (TypeMappingException $e) {
                throw TypeMappingException::wrapWithParamInfo($e, $parameter);
            } catch (CannotMapTypeExceptionInterface $e) {
                throw CannotMapTypeException::wrapWithParamInfo($e, $parameter);
            }

            if ($parameter->allowsNull()) {
                $arr['defaultValue'] = null;
            }
            if ($parameter->isDefaultValueAvailable()) {
                $arr['defaultValue'] = $parameter->getDefaultValue();
            }

            $args[$parameter->getName()] = $arr;
        }

        return $args;
    }

    private function mapType(Type $type, ?Type $docBlockType, bool $isNullable, bool $mapToInputType, ReflectionMethod $refMethod, DocBlock $docBlockObj, string $argumentName = null): GraphQLType
    {
        $graphQlType = null;

        if ($type instanceof Array_ || $type instanceof Iterable_ || $type instanceof Mixed_) {
            $graphQlType = $this->mapDocBlockType($type, $docBlockType, $isNullable, $mapToInputType, $refMethod, $docBlockObj, $argumentName);
        } else {
            try {
                $graphQlType = $this->toGraphQlType($type, null, $mapToInputType, $refMethod, $docBlockObj, $argumentName);
                if (!$isNullable) {
                    $graphQlType = GraphQLType::nonNull($graphQlType);
                }
            } catch (TypeMappingException | CannotMapTypeExceptionInterface $e) {
                // Is the type iterable? If yes, let's analyze the docblock
                // TODO: it would be better not to go through an exception for this.
                if ($type instanceof Object_) {
                    $fqcn = (string) $type->getFqsen();
                    $refClass = new ReflectionClass($fqcn);
                    // Note : $refClass->isIterable() is only accessible in PHP 7.2
                    if ($refClass->implementsInterface(Iterator::class) || $refClass->implementsInterface(IteratorAggregate::class)) {
                        $graphQlType = $this->mapIteratorDocBlockType($type, $docBlockType, $isNullable, $refMethod, $docBlockObj, $argumentName);
                    } else {
                        throw $e;
                    }
                } else {
                    throw $e;
                }
            }
        }

        return $graphQlType;
    }

    private function mapDocBlockType(Type $type, ?Type $docBlockType, bool $isNullable, bool $mapToInputType, ReflectionMethod $refMethod, DocBlock $docBlockObj, string $argumentName = null): GraphQLType
    {
        if ($docBlockType === null) {
            throw TypeMappingException::createFromType($type);
        }
        if (!$isNullable) {
            // Let's check a "null" value in the docblock
            $isNullable = $this->isNullable($docBlockType);
        }

        $filteredDocBlockTypes = $this->typesWithoutNullable($docBlockType);
        if (empty($filteredDocBlockTypes)) {
            throw TypeMappingException::createFromType($type);
        }

        $unionTypes = [];
        $lastException = null;
        foreach ($filteredDocBlockTypes as $singleDocBlockType) {
            try {
                $unionTypes[] = $this->toGraphQlType($this->dropNullableType($singleDocBlockType), null, $mapToInputType, $refMethod, $docBlockObj, $argumentName);
            } catch (TypeMappingException | CannotMapTypeExceptionInterface $e) {
                // We have several types. It is ok not to be able to match one.
                $lastException = $e;
            }
        }

        if (empty($unionTypes) && $lastException !== null) {
            throw $lastException;
        }

        if (count($unionTypes) === 1) {
            $graphQlType = $unionTypes[0];
        } else {
            $graphQlType = new UnionType($unionTypes, $this->typeMapper);
        }

        /* elseif (count($filteredDocBlockTypes) === 1) {
            $graphQlType = $this->toGraphQlType($filteredDocBlockTypes[0], $mapToInputType);
        } else {
            throw new GraphQLException('Union types are not supported (yet)');
            //$graphQlTypes = array_map([$this, 'toGraphQlType'], $filteredDocBlockTypes);
            //$$graphQlType = new UnionType($graphQlTypes);
        }*/

        if (!$isNullable) {
            $graphQlType = GraphQLType::nonNull($graphQlType);
        }
        return $graphQlType;
    }

    /**
     * Maps a type where the main PHP type is an iterator
     */
    private function mapIteratorDocBlockType(Type $type, ?Type $docBlockType, bool $isNullable, ReflectionMethod $refMethod, DocBlock $docBlockObj, string $argumentName = null): GraphQLType
    {
        if ($docBlockType === null) {
            throw TypeMappingException::createFromType($type);
        }
        if (!$isNullable) {
            // Let's check a "null" value in the docblock
            $isNullable = $this->isNullable($docBlockType);
        }

        $filteredDocBlockTypes = $this->typesWithoutNullable($docBlockType);
        if (empty($filteredDocBlockTypes)) {
            throw TypeMappingException::createFromType($type);
        }

        $unionTypes = [];
        $lastException = null;
        foreach ($filteredDocBlockTypes as $singleDocBlockType) {
            try {
                $singleDocBlockType = $this->getTypeInArray($singleDocBlockType);
                if ($singleDocBlockType !== null) {
                    $subGraphQlType = $this->toGraphQlType($singleDocBlockType, null, false, $refMethod, $docBlockObj);
                } else {
                    $subGraphQlType = null;
                }

                $unionTypes[] = $this->toGraphQlType($type, $subGraphQlType, false, $refMethod, $docBlockObj);

                // TODO: add here a scan of the $type variable and do stuff if it is iterable.
                // TODO: remove the iterator type if specified in the docblock (@return Iterator|User[])
                // TODO: check there is at least one array (User[])
            } catch (TypeMappingException | CannotMapTypeExceptionInterface $e) {
                // We have several types. It is ok not to be able to match one.
                $lastException = $e;
            }
        }

        if (empty($unionTypes) && $lastException !== null) {
            // We have an issue, let's try without the subType
            return $this->mapDocBlockType($type, $docBlockType, $isNullable, false, $refMethod, $docBlockObj);
        }

        if (count($unionTypes) === 1) {
            $graphQlType = $unionTypes[0];
        } else {
            $graphQlType = new UnionType($unionTypes, $this->typeMapper);
        }

        if (!$isNullable) {
            $graphQlType = GraphQLType::nonNull($graphQlType);
        }
        return $graphQlType;
    }

    /**
     * Casts a Type to a GraphQL type.
     * Does not deal with nullable.
     *
     * @param Type $type
     * @param GraphQLType|null $subType
     * @param bool $mapToInputType
     * @return GraphQLType (InputType&GraphQLType)|(OutputType&GraphQLType)
     * @throws CannotMapTypeExceptionInterface
     */
    private function toGraphQlType(Type $type, ?GraphQLType $subType, bool $mapToInputType, ReflectionMethod $refMethod, DocBlock $docBlockObj, string $argumentName = null): GraphQLType
    {
        if ($mapToInputType === true) {
            $mappedType = $this->rootTypeMapper->toGraphQLInputType($type, $subType, $argumentName, $refMethod, $docBlockObj);
        } else {
            $mappedType = $this->rootTypeMapper->toGraphQLOutputType($type, $subType, $refMethod, $docBlockObj);
        }
        if ($mappedType === null) {
            throw TypeMappingException::createFromType($type);
        }
        return $mappedType;
    }

    /**
     * Removes "null" from the type (if it is compound). Return an array of types (not a Compound type).
     *
     * @param Type $docBlockTypeHint
     * @return array
     */
    private function typesWithoutNullable(Type $docBlockTypeHint): array
    {
        if ($docBlockTypeHint instanceof Compound) {
            $docBlockTypeHints = \iterator_to_array($docBlockTypeHint);
        } else {
            $docBlockTypeHints = [$docBlockTypeHint];
        }
        return array_filter($docBlockTypeHints, function ($item) {
            return !$item instanceof Null_;
        });
    }

    /**
     * Drops "Nullable" types and return the core type.
     *
     * @param Type $typeHint
     * @return Type
     */
    private function dropNullableType(Type $typeHint): Type
    {
        if ($typeHint instanceof Nullable) {
            return $typeHint->getActualType();
        }
        return $typeHint;
    }

    /**
     * Resolves a list type.
     *
     * @param Type $typeHint
     * @return Type|null
     */
    private function getTypeInArray(Type $typeHint): ?Type
    {
        $typeHint = $this->dropNullableType($typeHint);

        if (!$typeHint instanceof Array_) {
            return null;
        }

        return $this->dropNullableType($typeHint->getValueType());
    }

    /**
     * @param Type $docBlockTypeHint
     * @return bool
     */
    private function isNullable(Type $docBlockTypeHint): bool
    {
        if ($docBlockTypeHint instanceof Null_) {
            return true;
        }
        if ($docBlockTypeHint instanceof Compound) {
            foreach ($docBlockTypeHint as $type) {
                if ($type instanceof Null_) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Resolves "self" types into the class type.
     *
     * @param Type $type
     * @return Type
     */
    private function resolveSelf(Type $type, ReflectionClass $reflectionClass): Type
    {
        if ($type instanceof Self_) {
            return new Object_(new Fqsen('\\'.$reflectionClass->getName()));
        }
        return $type;
    }
}
