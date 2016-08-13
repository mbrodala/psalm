<?php

namespace Psalm\Checker;

ini_set('xdebug.max_nesting_level', 512);

use PhpParser;
use Psalm\Issue\InvalidDocblock;
use Psalm\Issue\InvalidReturnType;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Context;
use Psalm\IssueBuffer;

class FunctionChecker implements StatementsSource
{
    protected $_function;
    protected $_aliased_classes = [];
    protected $_namespace;
    protected $_file_name;
    protected $_is_static = false;
    protected $_absolute_class;
    protected $_statements_checker;
    protected $_source;
    protected $_return_vars_in_scope = [];
    protected $_return_vars_possibly_in_scope = [];
    protected $_class_name;
    protected $_class_extends;

    /**
     * @var array
     */
    protected $_suppressed_issues;

    protected static $_no_effects_hashes = [];

    protected static $_function_params = [];
    protected static $_new_docblocks = [];
    protected static $_function_return_types = [];
    protected static $_function_namespaces = [];
    protected static $_existing_functions = [];
    protected static $_deprecated_functions = [];
    protected static $_have_registered_function = [];

    public function __construct(PhpParser\Node\FunctionLike $function, StatementsSource $source)
    {
        $this->_function = $function;
        $this->_aliased_classes = $source->getAliasedClasses();
        $this->_namespace = $source->getNamespace();
        $this->_class_name = $source->getClassName();
        $this->_class_extends = $source->getParentClass();
        $this->_file_name = $source->getFileName();
        $this->_absolute_class = $source->getAbsoluteClass();
        $this->_source = $source;
        $this->_suppressed_issues = $source->getSuppressedIssues();
    }

    public function check(Context $context, $check_methods = true)
    {
        if ($this->_function->stmts) {
            $has_context = (bool) count($context->vars_in_scope);
            if ($this instanceof ClassMethodChecker) {
                if (ClassLikeChecker::getThisClass()) {
                    $hash = $this->getMethodId() . json_encode([$context->vars_in_scope, $context->vars_possibly_in_scope]);

                    // if we know that the function has no effects on vars, we don't bother rechecking
                    if (isset(self::$_no_effects_hashes[$hash])) {
                        list($context->vars_in_scope, $context->vars_possibly_in_scope) = self::$_no_effects_hashes[$hash];

                        return;
                    }
                }
                elseif ($context->self) {
                    $context->vars_in_scope['this'] = new Type\Union([new Type\Atomic($context->self)]);
                }
            }

            $statements_checker = new StatementsChecker($this, $has_context, $check_methods);

            if ($this->_function instanceof PhpParser\Node\Stmt\ClassMethod) {
                $method_params = ClassMethodChecker::getMethodParams($this->getMethodId());

                foreach ($method_params as $method_param) {
                    $context->vars_in_scope[$method_param['name']] = StatementsChecker::fleshOutTypes(
                        clone $method_param['type'],
                        [],
                        $context->self,
                        $this->getMethodId()
                    );

                    $statements_checker->registerVariable($method_param['name'], $this->_function->getLine());
                }
            }
            else {
                // @todo deprecate this code
                foreach ($this->_function->params as $param) {
                    if ($param->type) {
                        if ($param->type instanceof PhpParser\Node\Name) {
                            if (!in_array($param->type->parts[0], ['self', 'parent'])) {
                                ClassLikeChecker::checkClassName($param->type, $this->_namespace, $this->_aliased_classes, $this->_file_name, $this->_suppressed_issues);
                            }
                        }
                    }

                    $is_nullable = $param->default !== null &&
                                    $param->default instanceof \PhpParser\Node\Expr\ConstFetch &&
                                    $param->default->name instanceof PhpParser\Node\Name &&
                                    $param->default->name->parts = ['null'];

                    if ($param->type) {
                        if ($param->type instanceof Type) {
                            $context->vars_in_scope[$param->name] = clone $param->type;
                        }
                        else {
                            if (is_string($param->type)) {
                                $param_type_string = $param->type;
                            }
                            elseif ($param->type instanceof PhpParser\Node\Name) {
                                $param_type_string = $param->type->parts === ['self']
                                                        ? $this->_absolute_class
                                                        : ClassLikeChecker::getAbsoluteClassFromName($param->type, $this->_namespace, $this->_aliased_classes);
                            }

                            if ($is_nullable) {
                                $param_type_string .= '|null';
                            }

                            $context->vars_in_scope[$param->name] = Type::parseString($param_type_string);
                        }
                    }
                    else {
                        $context->vars_in_scope[$param->name] = Type::getMixed();
                    }

                    $context->vars_possibly_in_scope[$param->name] = true;
                    $statements_checker->registerVariable($param->name, $param->getLine());
                }
            }

            $statements_checker->check($this->_function->stmts, $context);

            if (isset($this->_return_vars_in_scope[''])) {
                $context->vars_in_scope = TypeChecker::combineKeyedTypes($context->vars_in_scope, $this->_return_vars_in_scope['']);
            }

            if (isset($this->_return_vars_possibly_in_scope[''])) {
                $context->vars_possibly_in_scope = array_merge($context->vars_possibly_in_scope, $this->_return_vars_possibly_in_scope['']);
            }

            foreach ($context->vars_in_scope as $var => $type) {
                if (strpos($var, 'this->') !== 0) {
                    unset($context->vars_in_scope[$var]);
                }
            }

            foreach ($context->vars_possibly_in_scope as $var => $type) {
                if (strpos($var, 'this->') !== 0) {
                    unset($context->vars_possibly_in_scope[$var]);
                }
            }

            if (ClassLikeChecker::getThisClass() && $this instanceof ClassMethodChecker) {
                self::$_no_effects_hashes[$hash] = [$context->vars_in_scope, $context->vars_possibly_in_scope];
            }
        }
    }

    /**
     * Adds return types for the given function
     * @param string        $return_type
     * @param array<Type>   $context->vars_in_scope
     * @param array<bool>   $context->vars_possibly_in_scope
     */
    public function addReturnTypes($return_type, Context $context)
    {
        if (isset($this->_return_vars_in_scope[$return_type])) {
            $this->_return_vars_in_scope[$return_type] = TypeChecker::combineKeyedTypes($context->vars_in_scope, $this->_return_vars_in_scope[$return_type]);
        }
        else {
            $this->_return_vars_in_scope[$return_type] = $context->vars_in_scope;
        }

        if (isset($this->_return_vars_possibly_in_scope[$return_type])) {
            $this->_return_vars_possibly_in_scope[$return_type] = array_merge($context->vars_possibly_in_scope, $this->_return_vars_possibly_in_scope[$return_type]);
        }
        else {
            $this->_return_vars_possibly_in_scope[$return_type] = $context->vars_possibly_in_scope;
        }
    }

    /**
     * @return null|string
     */
    public function getMethodId()
    {
        if ($this->_function instanceof PhpParser\Node\Expr\Closure) {
            return null;
        }

        return ($this->_absolute_class ? $this->_absolute_class . '::' : '') . $this->_function->name;
    }

    public function getNamespace()
    {
        return $this->_namespace;
    }

    public function getAliasedClasses()
    {
        return $this->_aliased_classes;
    }

    public function getAbsoluteClass()
    {
        return $this->_absolute_class;
    }

    public function getClassName()
    {
        return $this->_class_name;
    }

    public function getClassLikeChecker()
    {
        return $this->_source->getClassLikeChecker();
    }

    public function getParentClass()
    {
        return $this->_class_extends;
    }

    public function getFileName()
    {
        return $this->_file_name;
    }

    public function isStatic()
    {
        return $this->_is_static;
    }

    public function getSource()
    {
        return $this->_source;
    }

    public function getSuppressedIssues()
    {
        return $this->_suppressed_issues;
    }

    public static function getFunctionReturnTypes($function_id, $file_name)
    {
        if (!isset(self::$_function_return_types[$file_name][$function_id])) {
            throw new \InvalidArgumentException('Do not know function');
        }

        return self::$_function_return_types[$file_name][$function_id]
            ? clone self::$_function_return_types[$file_name][$function_id]
            : null;
    }

    /**
     * @return false|null
     */
    public function checkReturnTypes($update_doc_comment = false)
    {
        if (!$this->_function->stmts) {
            return;
        }

        if ($this->_function->name === '__construct') {
            // we know that constructors always return this
            return;
        }

        if (!isset(self::$_new_docblocks[$this->_file_name])) {
            self::$_new_docblocks[$this->_file_name] = [];
        }

        $method_id = $this->getMethodId();

        if ($this instanceof ClassMethodChecker) {
            $method_return_types = ClassMethodChecker::getMethodReturnTypes($method_id);
        }
        else {
            $method_return_types = self::getFunctionReturnTypes($method_id, $this->_file_name);
        }

        if (!$method_return_types) {
            return;
        }

        // passing it through fleshOutTypes eradicates errant $ vars
        $declared_return_type = StatementsChecker::fleshOutTypes(
            $method_return_types,
            [],
            $this->_absolute_class,
            $method_id
        );

        if ($declared_return_type) {
            $inferred_return_types = \Psalm\EffectsAnalyser::getReturnTypes($this->_function->stmts, true);

            if (!$inferred_return_types) {
                if ($declared_return_type->isVoid()) {
                    return;
                }

                if (ScopeChecker::onlyThrows($this->_function->stmts)) {
                    // if there's a single throw statement, it's presumably an exception saying this method is not to be used
                    return;
                }

                if (IssueBuffer::accepts(
                    new InvalidReturnType(
                        'No return type was found for method ' . $method_id . ' but return type \'' . $declared_return_type . '\' was expected',
                        $this->_file_name,
                        $this->_function->getLine()
                    )
                )) {
                    return false;
                }

                return;
            }

            $inferred_return_type = Type::combineTypes($inferred_return_types);

            if ($inferred_return_type && !$inferred_return_type->isMixed() && !$declared_return_type->isMixed()) {
                if ($inferred_return_type->isNull() && $declared_return_type->isVoid()) {
                    return;
                }

                if (!TypeChecker::hasIdenticalTypes($declared_return_type, $inferred_return_type, $this->_absolute_class)) {
                    if (IssueBuffer::accepts(
                        new InvalidReturnType(
                            'The given return type \'' . $declared_return_type . '\' for ' . $method_id . ' is incorrect, got \'' . $inferred_return_type . '\'',
                            $this->_file_name,
                            $this->_function->getLine()
                        ),
                        $this->getSuppressedIssues()
                    )) {
                        return false;
                    }
                }
            }

            return;
        }
    }

    protected function _registerFunction(PhpParser\Node\Stmt\Function_ $function)
    {
        $function_id = $function->name;

        if (isset(self::$_have_registered_function[$this->_file_name][$function_id])) {
            throw new \LogicException('Cannot re-register function twice');
        }

        self::$_have_registered_function[$this->_file_name][$function_id] = true;

        self::$_function_namespaces[$this->_file_name][$function_id] = $this->_namespace;
        self::$_existing_functions[$this->_file_name][$function_id] = 1;

        self::$_function_params[$this->_file_name][$function_id] = [];

        $function_param_names = [];

        foreach ($function->getParams() as $param) {
            $param_array = $this->getParamArray($param);
            self::$_function_params[$this->_file_name][$function_id][] = $param_array;
            $function_param_names[$param->name] = $param_array['type'];
        }

        $config = Config::getInstance();
        $return_type = null;

        $docblock_info = CommentChecker::extractDocblockInfo($function->getDocComment());

        if ($docblock_info['deprecated']) {
            self::$_deprecated_functions[$this->_file_name][$function_id] = true;
        }

        $this->_suppressed_issues = $docblock_info['suppress'];

        if ($config->use_docblock_types) {
            if ($docblock_info['return_type']) {
                $return_type =
                    Type::parseString(
                        self::fixUpLocalType(
                            $docblock_info['return_type'],
                            null,
                            $this->_namespace,
                            $this->_aliased_classes
                        )
                    );
            }

            if ($docblock_info['params']) {
                $this->improveParamsFromDocblock(
                    $docblock_info['params'],
                    $function_param_names,
                    self::$_function_params[$this->_file_name][$function_id],
                    $function->getLine()
                );
            }
        }

        self::$_function_return_types[$this->_file_name][$function_id] = $return_type;
    }

    protected function improveParamsFromDocblock(array $docblock_params, array $function_param_names, array &$function_signature, $method_line_number)
    {
        foreach ($docblock_params as $docblock_param) {
            $param_name = $docblock_param['name'];

            if (!array_key_exists($param_name, $function_param_names)) {
                if (IssueBuffer::accepts(
                    new InvalidDocblock(
                        'Parameter $' . $param_name .' does not appear in the argument list for ' . $this->getMethodId(),
                        $this->_file_name,
                        $method_line_number
                    )
                )) {
                    return false;
                }

                continue;
            }

            $new_param_type =
                Type::parseString(
                    self::fixUpLocalType(
                        $docblock_param['type'],
                        null,
                        $this->_namespace,
                        $this->_aliased_classes
                    )
                );

            if ($function_param_names[$param_name] && !$function_param_names[$param_name]->isMixed()) {
                if (!$new_param_type->isIn($function_param_names[$param_name])) {
                    if (IssueBuffer::accepts(
                        new InvalidDocblock(
                            'Parameter $' . $param_name .' has wrong type \'' . $new_param_type . '\', should be \'' . $function_param_names[$param_name] . '\'',
                            $this->_file_name,
                            $method_line_number
                        )
                    )) {
                        return false;
                    }

                    continue;
                }
            }

            foreach ($function_signature as &$function_signature_param) {
                if ($function_signature_param['name'] === $param_name) {
                    $existing_param_type_nullable = $function_signature_param['is_nullable'];

                    if ($existing_param_type_nullable && !$new_param_type->isNullable()) {
                        $new_param_type->types['null'] = Type::getNull(false);
                    }

                    $function_signature_param['type'] = $new_param_type;
                    break;
                }
            }
        }
    }

    protected function getParamArray(PhpParser\Node\Param $param)
    {
        $param_type = null;

        $is_nullable = $param->default !== null &&
                        $param->default instanceof \PhpParser\Node\Expr\ConstFetch &&
                        $param->default->name instanceof PhpParser\Node\Name &&
                        $param->default->name->parts === ['null'];

        if ($param->type) {
            if (is_string($param->type)) {
                $param_type_string = $param->type;
            }
            elseif ($param->type instanceof PhpParser\Node\Name\FullyQualified) {
                $param_type_string = implode('\\', $param->type->parts);
            }
            elseif ($param->type->parts === ['self']) {
                $param_type_string = $this->_absolute_class;
            }
            else {
                $param_type_string = ClassLikeChecker::getAbsoluteClassFromString(implode('\\', $param->type->parts), $this->_namespace, $this->_aliased_classes);
            }

            if ($param_type_string) {
                if ($is_nullable) {
                    $param_type_string .= '|null';
                }

                $param_type = Type::parseString($param_type_string);
            }
        }

        $is_optional = $param->default !== null;

        return [
            'name' => $param->name,
            'by_ref' => $param->byRef,
            'type' => $param_type ?: Type::getMixed(),
            'is_optional' => $is_optional,
            'is_nullable' => $is_nullable,
        ];
    }

    public static function fixUpLocalType($return_type, $absolute_class, $namespace, $aliased_classes)
    {
        if (strpos($return_type, '[') !== false) {
            $return_type = Type::convertSquareBrackets($return_type);
        }

        $return_type_tokens = Type::tokenize($return_type);

        foreach ($return_type_tokens as &$return_type_token) {
            if ($return_type_token[0] === '\\') {
                $return_type_token = substr($return_type_token, 1);
                continue;
            }

            if (in_array($return_type_token, ['<', '>', '|', '?'])) {
                continue;
            }

            $return_type_token = Type::fixScalarTerms($return_type_token);

            if ($return_type_token[0] === strtoupper($return_type_token[0])) {
                if ($return_type === '$this' && $absolute_class) {
                    $return_type_token = $absolute_class;
                    continue;
                }

                $return_type_token = ClassLikeChecker::getAbsoluteClassFromString($return_type_token, $namespace, $aliased_classes);
            }
        }

        return implode('', $return_type_tokens);
    }
}