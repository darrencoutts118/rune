<?php

namespace uuf6429\Rune\Util;

use kamermans\Reflection\DocBlock;

class TypeAnalyser
{
    protected static $simpleTypes = [
        'object', 'array', 'string', 'boolean', 'integer', 'double',
    ];

    /**
     * List of discovered types, key is the fully qualified type name.
     *
     * @var array<string,TypeInfoClass>
     */
    protected $types = [];

    /**
     * Enables deep analysis (recursively analyses class members and their types).
     *
     * @var bool
     */
    protected $deep = false;

    /**
     * @var bool
     */
    protected $canInspectReflectionParamType;

    /**
     * @var bool
     */
    protected $canInspectReflectionReturnType;

    public function __construct()
    {
        $this->canInspectReflectionParamType = method_exists(\ReflectionParameter::class, 'getType');
        $this->canInspectReflectionReturnType = method_exists(\ReflectionMethod::class, 'getReturnType');
    }

    /**
     * @param string|array $type
     * @param bool         $deep
     */
    public function analyse($type, $deep = true)
    {
        if (is_array($type)) {
            foreach ($type as $aType) {
                $this->analyse($aType, $deep);
            }

            return;
        }

        $this->deep = $deep;
        $type = $this->normalise($type);

        if ($type && !isset($this->types[$type]) && !in_array($type, static::$simpleTypes, true)) {
            switch (true) {
                case @interface_exists($type):
                case @class_exists($type):
                    $this->analyseClassOrInterface($type);
                    break;

                case $type === 'callable':
                case $type === 'resource':
                    break;

                default:
                    throw new \RuntimeException(
                        sprintf(
                            'Type information for %s cannot be retrieved (unsupported type).',
                            $type
                        )
                    );
            }
        }
    }

    /**
     * @param string $name
     */
    protected function analyseClassOrInterface($name)
    {
        // .-- avoid infinite loop inspecting same type
        $this->types[$name] = 'IN_PROGRESS';

        $reflector = new \ReflectionClass($name);

        $docb = new DocBlock($reflector);
        $hint = $docb->getComment() ?: '';
        $link = $docb->getTag('link', '') ?: '';

        if (is_array($link)) {
            $link = $link[0];
        }

        $members = array_filter(
            array_merge(
                array_map(
                    [$this, 'parseDocBlockPropOrParam'],
                    $docb->getTag('property', [], true)
                ),
                array_map(
                    [$this, 'propertyToTypeInfoMember'],
                    $reflector->getProperties(\ReflectionProperty::IS_PUBLIC)
                ),
                array_map(
                    [$this, 'methodToTypeInfoMember'],
                    $reflector->getMethods(\ReflectionMethod::IS_PUBLIC)
                )
            )
        );

        $this->types[$name] = new TypeInfoClass($name, $members, $hint, $link);
    }

    /**
     * @param string $line
     *
     * @return null|TypeInfoMember
     */
    protected function parseDocBlockPropOrParam($line)
    {
        $regex = '/^([\\w\\|\\\\]+)\\s+(\\$\\w+)\\s*(.*)$/';
        if (preg_match($regex, trim($line), $result)) {
            $types = explode('|', $result[1]);
            $types = array_filter(array_map([$this, 'handleType'], $types));

            return new TypeInfoMember(
                substr($result[2], 1),
                $types,
                $result[3]
            );
        }

        return null;
    }

    /**
     * @param \ReflectionParameter $param
     *
     * @return null|TypeInfoMember
     */
    protected function parseReflectedParams(\ReflectionParameter $param)
    {
        $types = [];

        if ($this->canInspectReflectionParamType && (bool) ($type = $param->getType())) {
            $types[] = (string) $type;
            if ($type->allowsNull()) {
                $type[] = 'null';
            }
        }

        return new TypeInfoMember(
            $param->getName(),
            $types,
            ''
        );
    }

    /**
     * @param \ReflectionProperty $property
     *
     * @return TypeInfoMember
     */
    protected function propertyToTypeInfoMember(\ReflectionProperty $property)
    {
        $docb = new DocBlock($property);
        $hint = $docb->getComment();
        $link = $docb->getTag('link', '');
        $types = explode('|', $docb->getTag('var', ''));
        $types = array_filter(array_map([$this, 'handleType'], $types));

        return new TypeInfoMember($property->getName(), $types, $hint, $link);
    }

    /**
     * @param \ReflectionMethod $method
     *
     * @return TypeInfoMember|null
     */
    protected function methodToTypeInfoMember(\ReflectionMethod $method)
    {
        if (substr($method->name, 0, 2) === '__') {
            return null;
        }

        $docb = new DocBlock($method);
        $hint = $docb->getComment() ?: '';
        $link = $docb->getTag('link', '') ?: '';

        if (is_array($link)) {
            $link = $link[0];
        }

        if ($docb->tagExists('param')) {
            // detect return from docblock
            $return = explode(' ', $docb->getTag('return', 'void'), 2)[0];
        } else {
            // detect return from reflection
            $return = $this->canInspectReflectionReturnType
                ? $method->getReturnType() : '';
        }

        if ($docb->tagExists('param')) {
            // detect params from docblock
            $params = array_map(
                [$this, 'parseDocBlockPropOrParam'],
                $docb->getTag('param', [], true)
            );
        } else {
            // detect params from reflection
            $params = array_map(
                [$this, 'parseReflectedParams'],
                $method->getParameters()
            );
        }

        $signature = sprintf(
            '<div class="cm-signature">'
                    . '<span class="type">%s</span> <span class="name">%s</span>'
                    . '(<span class="args">%s</span>)</span>'
                . '</div>',
            $return,
            $method->name,
            implode(
                ', ',
                array_map(
                    function (TypeInfoMember $param) {
                        $result = '???';

                        if ($param) {
                            $result = sprintf(
                                '<span class="%s" title="%s"><span class="type">%s</span>$%s</span>',
                                $param->hasHint() ? 'arg hint' : 'arg',
                                $param->getHint(),
                                $param->hasTypes() ? (implode('|', $param->getTypes()) . ' ') : '',
                                $param->getName()
                            );
                        }

                        return $result;
                    },
                    $params
                )
            )
        );

        return new TypeInfoMember($method->name, ['method'], $signature . $hint, $link);
    }

    /**
     * @param string $name
     *
     * @return string
     */
    protected function handleType($name)
    {
        $name = $this->normalise($name);

        if ($this->deep) {
            $this->analyse($name);
        }

        return $name;
    }

    /**
     * @param string $type
     *
     * @return string
     */
    protected function normalise($type)
    {
        static $typeMap = [
            'int' => 'integer',
            'float' => 'double',
            'decimal' => 'double',
            'bool' => 'boolean',
            'stdClass' => 'object',
            'mixed' => '',
            'resource' => '',
        ];

        $type = ltrim($type, '\\');

        return isset($typeMap[$type]) ? $typeMap[$type] : $type;
    }

    /**
     * @return array<string,TypeInfoClass>
     */
    public function getTypes()
    {
        return $this->types;
    }
}
