<?php
namespace Psalm\Checker\Statements\Expression;

use PhpParser;
use Psalm\Checker\ClassLikeChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\TypeChecker;
use Psalm\CodeLocation;
use Psalm\FileSource;
use Psalm\Issue\DocblockTypeContradiction;
use Psalm\Issue\RedundantCondition;
use Psalm\Issue\RedundantConditionGivenDocblockType;
use Psalm\Issue\TypeDoesNotContainNull;
use Psalm\Issue\TypeDoesNotContainType;
use Psalm\Issue\UnevaluatedCode;
use Psalm\IssueBuffer;
use Psalm\StatementsSource;
use Psalm\Type;

class AssertionFinder
{
    const ASSIGNMENT_TO_RIGHT = 1;
    const ASSIGNMENT_TO_LEFT = -1;

    /**
     * Gets all the type assertions in a conditional
     *
     * @param string|null $this_class_name
     *
     * @return void
     */
    public static function scrapeAssertions(
        PhpParser\Node\Expr $conditional,
        $this_class_name,
        FileSource $source
    ) {
        if (isset($conditional->assertions)) {
            return;
        }

        $if_types = [];

        if ($conditional instanceof PhpParser\Node\Expr\Instanceof_) {
            $instanceof_type = self::getInstanceOfTypes($conditional, $this_class_name, $source);

            if ($instanceof_type) {
                $var_name = ExpressionChecker::getArrayVarId(
                    $conditional->expr,
                    $this_class_name,
                    $source
                );

                if ($var_name) {
                    $if_types[$var_name] = [[$instanceof_type]];
                }
            }

            $conditional->assertions = $if_types;
            return;
        }

        $var_name = ExpressionChecker::getArrayVarId(
            $conditional,
            $this_class_name,
            $source
        );

        if ($var_name) {
            $if_types[$var_name] = [['!falsy']];

            $conditional->assertions = $if_types;
            return;
        }

        if ($conditional instanceof PhpParser\Node\Expr\Assign) {
            $var_name = ExpressionChecker::getArrayVarId(
                $conditional->var,
                $this_class_name,
                $source
            );

            if ($var_name) {
                $if_types[$var_name] = [['!falsy']];
            }

            $conditional->assertions = $if_types;
            return;
        }

        if ($conditional instanceof PhpParser\Node\Expr\BooleanNot) {
            self::scrapeAssertions(
                $conditional->expr,
                $this_class_name,
                $source
            );

            if (!isset($conditional->expr->assertions)) {
                throw new \UnexpectedValueException('Assertions should be set');
            }

            $conditional->assertions = \Psalm\Type\Algebra::negateTypes($conditional->expr->assertions);
            return;
        }

        if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical ||
            $conditional instanceof PhpParser\Node\Expr\BinaryOp\Equal
        ) {
            self::scrapeEqualityAssertions($conditional, $this_class_name, $source);
            return;
        }

        if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical ||
            $conditional instanceof PhpParser\Node\Expr\BinaryOp\NotEqual
        ) {
            self::scrapeInequalityAssertions($conditional, $this_class_name, $source);
            return;
        }

        if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Greater) {
            $typed_value_position = self::hasTypedValueComparison($conditional);

            if ($typed_value_position) {
                if ($typed_value_position === self::ASSIGNMENT_TO_RIGHT) {
                    /** @var PhpParser\Node\Expr $conditional->right */
                    $var_name = ExpressionChecker::getArrayVarId(
                        $conditional->left,
                        $this_class_name,
                        $source
                    );
                } elseif ($typed_value_position === self::ASSIGNMENT_TO_LEFT) {
                    $var_name = null;
                } else {
                    throw new \UnexpectedValueException('$typed_value_position value');
                }

                if ($var_name) {
                    $if_types[$var_name] = [['^isset']];
                }

                $conditional->assertions = $if_types;
                return;
            }

            $conditional->assertions = [];
            return;
        }

        if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Smaller) {
            $typed_value_position = self::hasTypedValueComparison($conditional);

            if ($typed_value_position) {
                if ($typed_value_position === self::ASSIGNMENT_TO_RIGHT) {
                    $var_name = null;
                } elseif ($typed_value_position === self::ASSIGNMENT_TO_LEFT) {
                    /** @var PhpParser\Node\Expr $conditional->left */
                    $var_name = ExpressionChecker::getArrayVarId(
                        $conditional->right,
                        $this_class_name,
                        $source
                    );
                } else {
                    throw new \UnexpectedValueException('$typed_value_position value');
                }

                if ($var_name) {
                    $if_types[$var_name] = [['^isset']];
                }

                $conditional->assertions = $if_types;
                return;
            }

            $conditional->assertions = [];
            return;
        }

        if ($conditional instanceof PhpParser\Node\Expr\FuncCall) {
            $conditional->assertions = self::processFunctionCall($conditional, $this_class_name, $source, false);
            return;
        }

        if ($conditional instanceof PhpParser\Node\Expr\MethodCall) {
            $conditional->assertions = self::processCustomAssertion($conditional, $this_class_name, $source, false);
            return;
        }

        if ($conditional instanceof PhpParser\Node\Expr\Empty_) {
            $var_name = ExpressionChecker::getArrayVarId(
                $conditional->expr,
                $this_class_name,
                $source
            );

            if ($var_name) {
                $if_types[$var_name] = [['empty']];
            } else {
                // look for any variables we *can* use for an isset assertion
                $array_root = $conditional->expr;

                while ($array_root instanceof PhpParser\Node\Expr\ArrayDimFetch && !$var_name) {
                    $array_root = $array_root->var;

                    $var_name = ExpressionChecker::getArrayVarId(
                        $array_root,
                        $this_class_name,
                        $source
                    );
                }

                if ($var_name) {
                    $if_types[$var_name] = [['^empty']];
                }
            }

            $conditional->assertions = $if_types;
            return;
        }

        if ($conditional instanceof PhpParser\Node\Expr\Isset_) {
            foreach ($conditional->vars as $isset_var) {
                $var_name = ExpressionChecker::getArrayVarId(
                    $isset_var,
                    $this_class_name,
                    $source
                );

                if ($var_name) {
                    $if_types[$var_name] = [['isset']];
                } else {
                    // look for any variables we *can* use for an isset assertion
                    $array_root = $isset_var;

                    while ($array_root instanceof PhpParser\Node\Expr\ArrayDimFetch && !$var_name) {
                        $array_root = $array_root->var;

                        $var_name = ExpressionChecker::getArrayVarId(
                            $array_root,
                            $this_class_name,
                            $source
                        );
                    }

                    if ($var_name) {
                        $if_types[$var_name] = [['^isset']];
                    }
                }
            }

            $conditional->assertions = $if_types;
            return;
        }

        if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Coalesce) {
            $var_name = ExpressionChecker::getArrayVarId(
                $conditional->left,
                $this_class_name,
                $source
            );

            if ($var_name) {
                $if_types[$var_name] = [['isset']];
            } else {
                // look for any variables we *can* use for an isset assertion
                $array_root = $conditional->left;

                while ($array_root instanceof PhpParser\Node\Expr\ArrayDimFetch && !$var_name) {
                    $array_root = $array_root->var;

                    $var_name = ExpressionChecker::getArrayVarId(
                        $array_root,
                        $this_class_name,
                        $source
                    );
                }

                if ($var_name) {
                    $if_types[$var_name] = [['^isset']];
                }
            }

            $conditional->assertions = $if_types;
            return;
        }

        $conditional->assertions = [];
        return;
    }

    /**
     * @param PhpParser\Node\Expr\BinaryOp\Identical|PhpParser\Node\Expr\BinaryOp\Equal $conditional
     * @param string|null $this_class_name
     *
     * @return void
     */
    private static function scrapeEqualityAssertions(
        PhpParser\Node\Expr\BinaryOp $conditional,
        $this_class_name,
        FileSource $source
    ) {
        $project_checker = $source instanceof StatementsSource
            ? $source->getFileChecker()->project_checker
            : null;

        $if_types = [];

        $null_position = self::hasNullVariable($conditional);
        $false_position = self::hasFalseVariable($conditional);
        $true_position = self::hasTrueVariable($conditional);
        $gettype_position = self::hasGetTypeCheck($conditional);
        $getclass_position = self::hasGetClassCheck($conditional);
        $typed_value_position = self::hasTypedValueComparison($conditional);

        if ($null_position !== null) {
            if ($null_position === self::ASSIGNMENT_TO_RIGHT) {
                $base_conditional = $conditional->left;
            } elseif ($null_position === self::ASSIGNMENT_TO_LEFT) {
                $base_conditional = $conditional->right;
            } else {
                throw new \UnexpectedValueException('$null_position value');
            }

            $var_name = ExpressionChecker::getArrayVarId(
                $base_conditional,
                $this_class_name,
                $source
            );

            $var_type = isset($base_conditional->inferredType) ? $base_conditional->inferredType : null;

            if ($var_name) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical) {
                    $if_types[$var_name] = [['null']];
                } else {
                    $if_types[$var_name] = [['falsy']];
                }
            }

            if ($var_type
                && $conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical
                && $source instanceof StatementsSource
                && $project_checker
            ) {
                $null_type = Type::getNull();

                if (!TypeChecker::isContainedBy(
                    $project_checker->codebase,
                    $var_type,
                    $null_type
                ) && !TypeChecker::isContainedBy(
                    $project_checker->codebase,
                    $null_type,
                    $var_type
                )) {
                    if ($var_type->from_docblock) {
                        if (IssueBuffer::accepts(
                            new DocblockTypeContradiction(
                                $var_type . ' does not contain null',
                                new CodeLocation($source, $conditional)
                            ),
                            $source->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    } else {
                        if (IssueBuffer::accepts(
                            new TypeDoesNotContainNull(
                                $var_type . ' does not contain null',
                                new CodeLocation($source, $conditional)
                            ),
                            $source->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    }
                }
            }

            $conditional->assertions = $if_types;
            return;
        }

        if ($true_position) {
            if ($true_position === self::ASSIGNMENT_TO_RIGHT) {
                $base_conditional = $conditional->left;
            } elseif ($true_position === self::ASSIGNMENT_TO_LEFT) {
                $base_conditional = $conditional->right;
            } else {
                throw new \UnexpectedValueException('Unrecognised position');
            }

            if ($base_conditional instanceof PhpParser\Node\Expr\FuncCall) {
                $conditional->assertions = self::processFunctionCall(
                    $base_conditional,
                    $this_class_name,
                    $source,
                    false
                );
                return;
            }

            $var_name = ExpressionChecker::getArrayVarId(
                $base_conditional,
                $this_class_name,
                $source
            );

            $var_type = isset($base_conditional->inferredType) ? $base_conditional->inferredType : null;

            if ($var_name) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical) {
                    $if_types[$var_name] = [['true']];
                } else {
                    $if_types[$var_name] = [['!falsy']];
                }
            } else {
                self::scrapeAssertions($base_conditional, $this_class_name, $source);
                $if_types = $base_conditional->assertions;
            }

            if ($var_type) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical
                    && $source instanceof StatementsSource
                    && $project_checker
                ) {
                    $true_type = Type::getTrue();

                    if (!TypeChecker::isContainedBy(
                        $project_checker->codebase,
                        $var_type,
                        $true_type
                    ) && !TypeChecker::isContainedBy(
                        $project_checker->codebase,
                        $true_type,
                        $var_type
                    )) {
                        if ($var_type->from_docblock) {
                            if (IssueBuffer::accepts(
                                new DocblockTypeContradiction(
                                    $var_type . ' does not contain true',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        } else {
                            if (IssueBuffer::accepts(
                                new TypeDoesNotContainType(
                                    $var_type . ' does not contain true',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        }
                    }
                }
            }

            $conditional->assertions = $if_types;
            return;
        }

        if ($false_position) {
            if ($false_position === self::ASSIGNMENT_TO_RIGHT) {
                $base_conditional = $conditional->left;
            } elseif ($false_position === self::ASSIGNMENT_TO_LEFT) {
                $base_conditional = $conditional->right;
            } else {
                throw new \UnexpectedValueException('$false_position value');
            }

            if ($base_conditional instanceof PhpParser\Node\Expr\FuncCall) {
                $conditional->assertions = self::processFunctionCall(
                    $base_conditional,
                    $this_class_name,
                    $source,
                    true
                );
                return;
            }

            $var_name = ExpressionChecker::getArrayVarId(
                $base_conditional,
                $this_class_name,
                $source
            );

            $var_type = isset($base_conditional->inferredType) ? $base_conditional->inferredType : null;

            if ($var_name) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical) {
                    $if_types[$var_name] = [['false']];
                } else {
                    $if_types[$var_name] = [['falsy']];
                }
            } elseif ($var_type) {
                self::scrapeAssertions($base_conditional, $this_class_name, $source);

                if (!isset($base_conditional->assertions)) {
                    throw new \UnexpectedValueException('Assertions should be set');
                }

                $notif_types = $base_conditional->assertions;

                if (count($notif_types) === 1) {
                    $if_types = \Psalm\Type\Algebra::negateTypes($notif_types);
                }
            }

            if ($var_type) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical
                    && $source instanceof StatementsSource
                    && $project_checker
                ) {
                    $false_type = Type::getFalse();

                    if (!TypeChecker::isContainedBy(
                        $project_checker->codebase,
                        $var_type,
                        $false_type
                    ) && !TypeChecker::isContainedBy(
                        $project_checker->codebase,
                        $false_type,
                        $var_type
                    )) {
                        if ($var_type->from_docblock) {
                            if (IssueBuffer::accepts(
                                new DocblockTypeContradiction(
                                    $var_type . ' does not contain false',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        } else {
                            if (IssueBuffer::accepts(
                                new TypeDoesNotContainType(
                                    $var_type . ' does not contain false',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        }
                    }
                }
            }

            $conditional->assertions = $if_types;
            return;
        }

        if ($gettype_position) {
            if ($gettype_position === self::ASSIGNMENT_TO_RIGHT) {
                $string_expr = $conditional->left;
                $gettype_expr = $conditional->right;
            } elseif ($gettype_position === self::ASSIGNMENT_TO_LEFT) {
                $string_expr = $conditional->right;
                $gettype_expr = $conditional->left;
            } else {
                throw new \UnexpectedValueException('$gettype_position value');
            }

            /** @var PhpParser\Node\Expr\FuncCall $gettype_expr */
            $var_name = ExpressionChecker::getArrayVarId(
                $gettype_expr->args[0]->value,
                $this_class_name,
                $source
            );

            /** @var PhpParser\Node\Scalar\String_ $string_expr */
            $var_type = $string_expr->value;

            if (!isset(ClassLikeChecker::$GETTYPE_TYPES[$var_type])
                && $source instanceof StatementsSource
            ) {
                if (IssueBuffer::accepts(
                    new UnevaluatedCode(
                        'gettype cannot return this value',
                        new CodeLocation($source, $string_expr)
                    )
                )) {
                    // fall through
                }
            } else {
                if ($var_name && $var_type) {
                    $if_types[$var_name] = [[$var_type]];
                }
            }

            $conditional->assertions = $if_types;
            return;
        }

        if ($getclass_position) {
            if ($getclass_position === self::ASSIGNMENT_TO_RIGHT) {
                $whichclass_expr = $conditional->left;
                $getclass_expr = $conditional->right;
            } elseif ($getclass_position === self::ASSIGNMENT_TO_LEFT) {
                $whichclass_expr = $conditional->right;
                $getclass_expr = $conditional->left;
            } else {
                throw new \UnexpectedValueException('$getclass_position value');
            }

            if ($getclass_expr instanceof PhpParser\Node\Expr\FuncCall) {
                $var_name = ExpressionChecker::getArrayVarId(
                    $getclass_expr->args[0]->value,
                    $this_class_name,
                    $source
                );
            } else {
                $var_name = '$this';
            }

            if ($whichclass_expr instanceof PhpParser\Node\Scalar\String_) {
                $var_type = $whichclass_expr->value;
            } elseif ($whichclass_expr instanceof PhpParser\Node\Expr\ClassConstFetch
                && $whichclass_expr->class instanceof PhpParser\Node\Name
            ) {
                $var_type = ClassLikeChecker::getFQCLNFromNameObject(
                    $whichclass_expr->class,
                    $source->getAliases()
                );

                if ($var_type === 'self') {
                    $var_type = $this_class_name;
                } elseif ($var_type === 'parent' || $var_type === 'static') {
                    $var_type = null;
                }
            } else {
                throw new \UnexpectedValueException('Shouldn???t get here');
            }

            if ($source instanceof StatementsSource
                && $var_type
                && ClassLikeChecker::checkFullyQualifiedClassLikeName(
                    $source,
                    $var_type,
                    new CodeLocation($source, $whichclass_expr),
                    $source->getSuppressedIssues(),
                    false
                ) === false
            ) {
                // fall through
            } else {
                if ($var_name && $var_type) {
                    $if_types[$var_name] = [['^getclass-' . $var_type]];
                }
            }

            $conditional->assertions = $if_types;
            return;
        }

        if ($typed_value_position) {
            if ($typed_value_position === self::ASSIGNMENT_TO_RIGHT) {
                /** @var PhpParser\Node\Expr $conditional->right */
                $var_name = ExpressionChecker::getArrayVarId(
                    $conditional->left,
                    $this_class_name,
                    $source
                );

                $other_type = isset($conditional->left->inferredType) ? $conditional->left->inferredType : null;
                $var_type = $conditional->right->inferredType;
            } elseif ($typed_value_position === self::ASSIGNMENT_TO_LEFT) {
                /** @var PhpParser\Node\Expr $conditional->left */
                $var_name = ExpressionChecker::getArrayVarId(
                    $conditional->right,
                    $this_class_name,
                    $source
                );

                $var_type = $conditional->left->inferredType;
                $other_type = isset($conditional->right->inferredType) ? $conditional->right->inferredType : null;
            } else {
                throw new \UnexpectedValueException('$typed_value_position value');
            }

            if ($var_name && $var_type) {
                $identical = $conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical
                    || ($other_type
                        && (($var_type->isString() && $other_type->isString())
                            || ($var_type->isInt() && $other_type->isInt())
                            || ($var_type->isFloat() && $other_type->isFloat())
                        )
                    );

                if ($identical) {
                    $if_types[$var_name] = [['^' . $var_type->getId()]];
                } else {
                    $if_types[$var_name] = [['~' . $var_type->getId()]];
                }
            }

            if ($other_type
                && $var_type
                && $conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical
                && $source instanceof StatementsSource
                && $project_checker
            ) {
                if (!TypeChecker::isContainedBy(
                    $project_checker->codebase,
                    $var_type,
                    $other_type,
                    true,
                    true
                ) && !TypeChecker::isContainedBy(
                    $project_checker->codebase,
                    $other_type,
                    $var_type,
                    true,
                    true
                )) {
                    if ($var_type->from_docblock || $other_type->from_docblock) {
                        if (IssueBuffer::accepts(
                            new DocblockTypeContradiction(
                                $var_type . ' does not contain ' . $other_type,
                                new CodeLocation($source, $conditional)
                            ),
                            $source->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    } else {
                        if (IssueBuffer::accepts(
                            new TypeDoesNotContainType(
                                $var_type->getId() . ' does not contain ' . $other_type->getId(),
                                new CodeLocation($source, $conditional)
                            ),
                            $source->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    }
                }
            }

            $conditional->assertions = $if_types;
            return;
        }

        $var_type = isset($conditional->left->inferredType) ? $conditional->left->inferredType : null;
        $other_type = isset($conditional->right->inferredType) ? $conditional->right->inferredType : null;

        if ($var_type
            && $other_type
            && $conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical
            && $source instanceof StatementsSource
            && $project_checker
        ) {
            if (!TypeChecker::canBeIdenticalTo($project_checker->codebase, $var_type, $other_type)) {
                if (IssueBuffer::accepts(
                    new TypeDoesNotContainType(
                        $var_type . ' does not contain ' . $other_type,
                        new CodeLocation($source, $conditional)
                    ),
                    $source->getSuppressedIssues()
                )) {
                    // fall through
                }
            }
        }

        $conditional->assertions = [];
        return;
    }

    /**
     * @param PhpParser\Node\Expr\BinaryOp\NotIdentical|PhpParser\Node\Expr\BinaryOp\NotEqual $conditional
     * @param string|null $this_class_name
     *
     * @return void
     */
    private static function scrapeInequalityAssertions(
        PhpParser\Node\Expr\BinaryOp $conditional,
        $this_class_name,
        FileSource $source
    ) {
        $if_types = [];

        $project_checker = $source instanceof StatementsSource
            ? $source->getFileChecker()->project_checker
            : null;

        $null_position = self::hasNullVariable($conditional);
        $false_position = self::hasFalseVariable($conditional);
        $true_position = self::hasTrueVariable($conditional);
        $gettype_position = self::hasGetTypeCheck($conditional);
        $getclass_position = self::hasGetClassCheck($conditional);
        $typed_value_position = self::hasTypedValueComparison($conditional);

        if ($null_position !== null) {
            if ($null_position === self::ASSIGNMENT_TO_RIGHT) {
                $base_conditional = $conditional->left;
            } elseif ($null_position === self::ASSIGNMENT_TO_LEFT) {
                $base_conditional = $conditional->right;
            } else {
                throw new \UnexpectedValueException('Bad null variable position');
            }

            $var_type = isset($base_conditional->inferredType) ? $base_conditional->inferredType : null;

            $var_name = ExpressionChecker::getArrayVarId(
                $base_conditional,
                $this_class_name,
                $source
            );

            if ($var_name) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical) {
                    $if_types[$var_name] = [['!null']];
                } else {
                    $if_types[$var_name] = [['!falsy']];
                }
            }

            if ($var_type) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical
                    && $source instanceof StatementsSource
                    && $project_checker
                ) {
                    $null_type = Type::getNull();

                    if (!TypeChecker::isContainedBy(
                        $project_checker->codebase,
                        $var_type,
                        $null_type
                    ) && !TypeChecker::isContainedBy(
                        $project_checker->codebase,
                        $null_type,
                        $var_type
                    )) {
                        if ($var_type->from_docblock) {
                            if (IssueBuffer::accepts(
                                new RedundantConditionGivenDocblockType(
                                    'Docblock-asserted type ' . $var_type . ' can never contain null',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        } else {
                            if (IssueBuffer::accepts(
                                new RedundantCondition(
                                    $var_type . ' can never contain null',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        }
                    }
                }
            }

            $conditional->assertions = $if_types;
            return;
        }

        if ($false_position) {
            if ($false_position === self::ASSIGNMENT_TO_RIGHT) {
                $base_conditional = $conditional->left;
            } elseif ($false_position === self::ASSIGNMENT_TO_LEFT) {
                $base_conditional = $conditional->right;
            } else {
                throw new \UnexpectedValueException('Bad false variable position');
            }

            $var_name = ExpressionChecker::getArrayVarId(
                $base_conditional,
                $this_class_name,
                $source
            );

            $var_type = isset($base_conditional->inferredType) ? $base_conditional->inferredType : null;

            if ($var_name) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical) {
                    $if_types[$var_name] = [['!false']];
                } else {
                    $if_types[$var_name] = [['!falsy']];
                }
            } elseif ($var_type) {
                self::scrapeAssertions($base_conditional, $this_class_name, $source);

                if (!isset($base_conditional->assertions)) {
                    throw new \UnexpectedValueException('Assertions should be set');
                }

                $notif_types = $base_conditional->assertions;

                if (count($notif_types) === 1) {
                    $if_types = \Psalm\Type\Algebra::negateTypes($notif_types);
                }
            }

            if ($var_type) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical
                    && $source instanceof StatementsSource
                    && $project_checker
                ) {
                    $false_type = Type::getFalse();

                    if (!TypeChecker::isContainedBy(
                        $project_checker->codebase,
                        $var_type,
                        $false_type
                    ) && !TypeChecker::isContainedBy(
                        $project_checker->codebase,
                        $false_type,
                        $var_type
                    )) {
                        if ($var_type->from_docblock) {
                            if (IssueBuffer::accepts(
                                new RedundantConditionGivenDocblockType(
                                    'Docblock-asserted type ' . $var_type . ' can never contain false',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        } else {
                            if (IssueBuffer::accepts(
                                new RedundantCondition(
                                    $var_type . ' can never contain false',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        }
                    }
                }
            }

            $conditional->assertions = $if_types;
            return;
        }

        if ($true_position) {
            if ($true_position === self::ASSIGNMENT_TO_RIGHT) {
                if ($conditional->left instanceof PhpParser\Node\Expr\FuncCall) {
                    $conditional->assertions = self::processFunctionCall(
                        $conditional->left,
                        $this_class_name,
                        $source,
                        true
                    );
                    return;
                }

                $base_conditional = $conditional->left;
            } elseif ($true_position === self::ASSIGNMENT_TO_LEFT) {
                if ($conditional->right instanceof PhpParser\Node\Expr\FuncCall) {
                    $conditional->assertions = self::processFunctionCall(
                        $conditional->right,
                        $this_class_name,
                        $source,
                        true
                    );
                    return;
                }

                $base_conditional = $conditional->right;
            } else {
                throw new \UnexpectedValueException('Bad null variable position');
            }

            $var_name = ExpressionChecker::getArrayVarId(
                $base_conditional,
                $this_class_name,
                $source
            );

            $var_type = isset($base_conditional->inferredType) ? $base_conditional->inferredType : null;

            if ($var_name) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical) {
                    $if_types[$var_name] = [['!true']];
                } else {
                    $if_types[$var_name] = [['falsy']];
                }
            } elseif ($var_type) {
                self::scrapeAssertions($base_conditional, $this_class_name, $source);

                if (!isset($base_conditional->assertions)) {
                    throw new \UnexpectedValueException('Assertions should be set');
                }

                $notif_types = $base_conditional->assertions;

                if (count($notif_types) === 1) {
                    $if_types = \Psalm\Type\Algebra::negateTypes($notif_types);
                }
            }

            if ($var_type) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical
                    && $source instanceof StatementsSource
                    && $project_checker
                ) {
                    $true_type = Type::getTrue();

                    if (!TypeChecker::isContainedBy(
                        $project_checker->codebase,
                        $var_type,
                        $true_type
                    ) && !TypeChecker::isContainedBy(
                        $project_checker->codebase,
                        $true_type,
                        $var_type
                    )) {
                        if ($var_type->from_docblock) {
                            if (IssueBuffer::accepts(
                                new RedundantConditionGivenDocblockType(
                                    'Docblock-asserted type ' . $var_type . ' can never contain true',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        } else {
                            if (IssueBuffer::accepts(
                                new RedundantCondition(
                                    $var_type . ' can never contain ' . $true_type,
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        }
                    }
                }
            }

            $conditional->assertions = $if_types;
            return;
        }

        if ($gettype_position) {
            if ($gettype_position === self::ASSIGNMENT_TO_RIGHT) {
                $whichclass_expr = $conditional->left;
                $gettype_expr = $conditional->right;
            } elseif ($gettype_position === self::ASSIGNMENT_TO_LEFT) {
                $whichclass_expr = $conditional->right;
                $gettype_expr = $conditional->left;
            } else {
                throw new \UnexpectedValueException('$gettype_position value');
            }

            /** @var PhpParser\Node\Expr\FuncCall $gettype_expr */
            $var_name = ExpressionChecker::getArrayVarId(
                $gettype_expr->args[0]->value,
                $this_class_name,
                $source
            );

            if ($whichclass_expr instanceof PhpParser\Node\Scalar\String_) {
                $var_type = $whichclass_expr->value;
            } elseif ($whichclass_expr instanceof PhpParser\Node\Expr\ClassConstFetch
                && $whichclass_expr->class instanceof PhpParser\Node\Name
            ) {
                $var_type = ClassLikeChecker::getFQCLNFromNameObject(
                    $whichclass_expr->class,
                    $source->getAliases()
                );
            } else {
                throw new \UnexpectedValueException('Shouldn???t get here');
            }

            if (!isset(ClassLikeChecker::$GETTYPE_TYPES[$var_type])) {
                if (IssueBuffer::accepts(
                    new UnevaluatedCode(
                        'gettype cannot return this value',
                        new CodeLocation($source, $whichclass_expr)
                    )
                )) {
                    // fall through
                }
            } else {
                if ($var_name && $var_type) {
                    $if_types[$var_name] = [['!' . $var_type]];
                }
            }

            $conditional->assertions = $if_types;
            return;
        }

        if ($getclass_position) {
            if ($getclass_position === self::ASSIGNMENT_TO_RIGHT) {
                $whichclass_expr = $conditional->left;
                $getclass_expr = $conditional->right;
            } elseif ($getclass_position === self::ASSIGNMENT_TO_LEFT) {
                $whichclass_expr = $conditional->right;
                $getclass_expr = $conditional->left;
            } else {
                throw new \UnexpectedValueException('$getclass_position value');
            }

            if ($getclass_expr instanceof PhpParser\Node\Expr\FuncCall) {
                $var_name = ExpressionChecker::getArrayVarId(
                    $getclass_expr->args[0]->value,
                    $this_class_name,
                    $source
                );
            } else {
                $var_name = '$this';
            }

            if ($whichclass_expr instanceof PhpParser\Node\Scalar\String_) {
                $var_type = $whichclass_expr->value;
            } elseif ($whichclass_expr instanceof PhpParser\Node\Expr\ClassConstFetch
                && $whichclass_expr->class instanceof PhpParser\Node\Name
            ) {
                $var_type = ClassLikeChecker::getFQCLNFromNameObject(
                    $whichclass_expr->class,
                    $source->getAliases()
                );

                if ($var_type === 'self') {
                    $var_type = $this_class_name;
                } elseif ($var_type === 'parent' || $var_type === 'static') {
                    $var_type = null;
                }
            } else {
                throw new \UnexpectedValueException('Shouldn???t get here');
            }

            if ($source instanceof StatementsSource
                && $project_checker
                && $var_type
                && ClassLikeChecker::checkFullyQualifiedClassLikeName(
                    $source,
                    $var_type,
                    new CodeLocation($source, $whichclass_expr),
                    $source->getSuppressedIssues(),
                    false
                ) === false
            ) {
                // fall through
            } else {
                if ($var_name && $var_type) {
                    $if_types[$var_name] = [['!^getclass-' . $var_type]];
                }
            }

            $conditional->assertions = $if_types;
            return;
        }

        if ($typed_value_position) {
            if ($typed_value_position === self::ASSIGNMENT_TO_RIGHT) {
                /** @var PhpParser\Node\Expr $conditional->right */
                $var_name = ExpressionChecker::getArrayVarId(
                    $conditional->left,
                    $this_class_name,
                    $source
                );

                $other_type = isset($conditional->left->inferredType) ? $conditional->left->inferredType : null;
                $var_type = isset($conditional->right->inferredType) ? $conditional->right->inferredType : null;
            } elseif ($typed_value_position === self::ASSIGNMENT_TO_LEFT) {
                /** @var PhpParser\Node\Expr $conditional->left */
                $var_name = ExpressionChecker::getArrayVarId(
                    $conditional->right,
                    $this_class_name,
                    $source
                );

                $var_type = isset($conditional->left->inferredType) ? $conditional->left->inferredType : null;
                $other_type = isset($conditional->right->inferredType) ? $conditional->right->inferredType : null;
            } else {
                throw new \UnexpectedValueException('$typed_value_position value');
            }

            if ($var_type) {
                if ($var_name) {
                    $not_identical = $conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical
                        || ($other_type
                            && (($var_type->isString() && $other_type->isString())
                                || ($var_type->isInt() && $other_type->isInt())
                                || ($var_type->isFloat() && $other_type->isFloat())
                            )
                        );

                    if ($not_identical) {
                        $if_types[$var_name] = [['!^' . $var_type->getId()]];
                    } else {
                        $if_types[$var_name] = [['!~' . $var_type->getId()]];
                    }
                }

                if ($other_type
                    && $conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical
                    && $source instanceof StatementsSource
                    && $project_checker
                ) {
                    if (!TypeChecker::isContainedBy(
                        $project_checker->codebase,
                        $var_type,
                        $other_type,
                        true,
                        true
                    ) && !TypeChecker::isContainedBy(
                        $project_checker->codebase,
                        $other_type,
                        $var_type,
                        true,
                        true
                    )) {
                        if ($var_type->from_docblock || $other_type->from_docblock) {
                            if (IssueBuffer::accepts(
                                new DocblockTypeContradiction(
                                    $var_type . ' can never contain ' . $other_type,
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        } else {
                            if (IssueBuffer::accepts(
                                new RedundantCondition(
                                    $var_type->getId() . ' can never contain ' . $other_type->getId(),
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        }
                    }
                }
            }

            $conditional->assertions = $if_types;
            return;
        }

        $conditional->assertions = [];
        return;
    }

    /**
     * @param  PhpParser\Node\Expr\FuncCall $expr
     * @param  string|null                  $this_class_name
     * @param  FileSource                   $source
     * @param  bool                         $negate
     *
     * @return array<string, array<int, array<int, string>>>
     */
    protected static function processFunctionCall(
        PhpParser\Node\Expr\FuncCall $expr,
        $this_class_name,
        FileSource $source,
        $negate = false
    ) {
        $prefix = $negate ? '!' : '';

        $first_var_name = isset($expr->args[0]->value)
            ? ExpressionChecker::getArrayVarId(
                $expr->args[0]->value,
                $this_class_name,
                $source
            )
            : null;

        $if_types = [];

        if (self::hasNullCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'null']];
            }
        } elseif (self::hasIsACheck($expr)) {
            if ($expr->args[0]->value instanceof PhpParser\Node\Expr\ClassConstFetch
                && is_string($expr->args[0]->value->name)
                && strtolower($expr->args[0]->value->name) === 'class'
                && $expr->args[0]->value->class instanceof PhpParser\Node\Name
                && count($expr->args[0]->value->class->parts) === 1
                && strtolower($expr->args[0]->value->class->parts[0]) === 'static'
            ) {
                $first_var_name = '$this';
            }

            if ($first_var_name) {
                $second_arg = $expr->args[1]->value;

                $is_a_prefix = '';

                if (isset($expr->args[2]->value)) {
                    $third_arg = $expr->args[2]->value;

                    if (!$third_arg instanceof PhpParser\Node\Expr\ConstFetch
                        || !in_array(strtolower($third_arg->name->parts[0]), ['true', 'false'])
                    ) {
                        return $if_types;
                    }

                    $is_a_prefix = strtolower($third_arg->name->parts[0]) === 'true' ? 'isa-' : '';
                }

                if ($second_arg instanceof PhpParser\Node\Scalar\String_) {
                    $if_types[$first_var_name] = [[$prefix . $is_a_prefix . $second_arg->value]];
                } elseif ($second_arg instanceof PhpParser\Node\Expr\ClassConstFetch
                    && $second_arg->class instanceof PhpParser\Node\Name
                    && is_string($second_arg->name)
                    && strtolower($second_arg->name) === 'class'
                ) {
                    $first_arg = $expr->args[0]->value;

                    if (isset($first_arg->inferredType)
                        && $first_arg->inferredType->isSingleStringLiteral()
                        && $source instanceof StatementsChecker
                        && $source->getSource()->getSource() instanceof \Psalm\Checker\TraitChecker
                        && $first_arg->inferredType->getSingleStringLiteral()->value === $this_class_name
                    ) {
                        // do nothing
                    } else {
                        $class_node = $second_arg->class;

                        if ($class_node->parts === ['static'] || $class_node->parts === ['self']) {
                            if ($this_class_name) {
                                $if_types[$first_var_name] = [[$prefix . $is_a_prefix . $this_class_name]];
                            }
                        } elseif ($class_node->parts === ['parent']) {
                            // do nothing
                        } else {
                            $if_types[$first_var_name] = [[
                                $prefix . $is_a_prefix
                                    . ClassLikeChecker::getFQCLNFromNameObject(
                                        $class_node,
                                        $source->getAliases()
                                    )
                            ]];
                        }
                    }
                }
            }
        } elseif (self::hasArrayCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'array']];
            }
        } elseif (self::hasBoolCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'bool']];
            }
        } elseif (self::hasStringCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'string']];
            }
        } elseif (self::hasObjectCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'object']];
            }
        } elseif (self::hasNumericCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'numeric']];
            }
        } elseif (self::hasIntCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'int']];
            }
        } elseif (self::hasFloatCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'float']];
            }
        } elseif (self::hasResourceCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'resource']];
            }
        } elseif (self::hasScalarCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'scalar']];
            }
        } elseif (self::hasCallableCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'callable']];
            }
        } elseif (self::hasIterableCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'iterable']];
            }
        } elseif (self::hasInArrayCheck($expr)) {
            if ($first_var_name && isset($expr->args[1]->value->inferredType)) {
                foreach ($expr->args[1]->value->inferredType->getTypes() as $atomic_type) {
                    if ($atomic_type instanceof Type\Atomic\TArray
                        || $atomic_type instanceof Type\Atomic\ObjectLike
                    ) {
                        if ($atomic_type instanceof Type\Atomic\ObjectLike) {
                            $atomic_type = $atomic_type->getGenericArrayType();
                        }

                        $array_literal_types = array_merge(
                            $atomic_type->type_params[1]->getLiteralStrings(),
                            $atomic_type->type_params[1]->getLiteralInts(),
                            $atomic_type->type_params[1]->getLiteralFloats()
                        );

                        if (count($atomic_type->type_params[1]->getTypes()) === count($array_literal_types)) {
                            $literal_assertions = [];

                            foreach ($array_literal_types as $array_literal_type) {
                                $literal_assertions[] = '^' . $array_literal_type->getId();
                            }

                            if ($negate) {
                                $if_types = \Psalm\Type\Algebra::negateTypes([
                                    $first_var_name => [$literal_assertions]
                                ]);
                            } else {
                                $if_types[$first_var_name] = [$literal_assertions];
                            }
                        }
                    }
                }
            }
        } elseif (self::hasArrayKeyExistsCheck($expr)) {
            $array_root = isset($expr->args[1]->value)
                ? ExpressionChecker::getArrayVarId(
                    $expr->args[1]->value,
                    $this_class_name,
                    $source
                )
                : null;

            if ($first_var_name === null && isset($expr->args[0])) {
                $first_arg = $expr->args[0];

                if ($first_arg->value instanceof PhpParser\Node\Scalar\String_) {
                    $first_var_name = '"' . $first_arg->value->value . '"';
                } elseif ($first_arg->value instanceof PhpParser\Node\Scalar\LNumber) {
                    $first_var_name = (string) $first_arg->value->value;
                }
            }

            if ($first_var_name !== null
                && $array_root
                && !strpos($first_var_name, '->')
                && !strpos($first_var_name, '[')
            ) {
                $if_types[$array_root . '[' . $first_var_name . ']'] = [[$prefix . 'array-key-exists']];
            }
        } else {
            $if_types = self::processCustomAssertion($expr, $this_class_name, $source, $negate);
        }

        return $if_types;
    }

    /**
     * @param  PhpParser\Node\Expr\FuncCall|PhpParser\Node\Expr\MethodCall      $expr
     * @param  string|null  $this_class_name
     * @param  FileSource   $source
     * @param  bool         $negate
     *
     * @return array<string, array<int, array<int, string>>>
     */
    protected static function processCustomAssertion(
        $expr,
        $this_class_name,
        FileSource $source,
        $negate = false
    ) {
        if (!$source instanceof StatementsChecker
            || (!isset($expr->ifTrueAssertions) && !isset($expr->ifFalseAssertions))
        ) {
            return [];
        }

        $prefix = $negate ? '!' : '';

        $first_var_name = isset($expr->args[0]->value)
            ? ExpressionChecker::getArrayVarId(
                $expr->args[0]->value,
                $this_class_name,
                $source
            )
            : null;

        $if_types = [];

        if (isset($expr->ifTrueAssertions)) {
            foreach ($expr->ifTrueAssertions as $assertion) {
                if (is_int($assertion->var_id) && isset($expr->args[$assertion->var_id])) {
                    if ($assertion->var_id === 0) {
                        $var_name = $first_var_name;
                    } else {
                        $var_name = ExpressionChecker::getArrayVarId(
                            $expr->args[$assertion->var_id]->value,
                            $this_class_name,
                            $source
                        );
                    }

                    if ($var_name) {
                        if ($prefix === $assertion->rule[0][0][0]) {
                            $if_types[$var_name] = [[substr($assertion->rule[0][0], 1)]];
                        } else {
                            $if_types[$var_name] = [[$prefix . $assertion->rule[0][0]]];
                        }
                    }
                }
            }
        }

        if (isset($expr->ifFalseAssertions)) {
            $negated_prefix = !$negate ? '!' : '';

            foreach ($expr->ifFalseAssertions as $assertion) {
                if (is_int($assertion->var_id) && isset($expr->args[$assertion->var_id])) {
                    if ($assertion->var_id === 0) {
                        $var_name = $first_var_name;
                    } else {
                        $var_name = ExpressionChecker::getArrayVarId(
                            $expr->args[$assertion->var_id]->value,
                            $this_class_name,
                            $source
                        );
                    }

                    if ($var_name) {
                        if ($negated_prefix === $assertion->rule[0][0][0]) {
                            $if_types[$var_name] = [[substr($assertion->rule[0][0], 1)]];
                        } else {
                            $if_types[$var_name] = [[$negated_prefix . $assertion->rule[0][0]]];
                        }
                    }
                }
            }
        }

        return $if_types;
    }

    /**
     * @param  PhpParser\Node\Expr\Instanceof_ $stmt
     * @param  string|null                     $this_class_name
     * @param  FileSource                $source
     *
     * @return string|null
     */
    protected static function getInstanceOfTypes(
        PhpParser\Node\Expr\Instanceof_ $stmt,
        $this_class_name,
        FileSource $source
    ) {
        if ($stmt->class instanceof PhpParser\Node\Name) {
            if (!in_array(strtolower($stmt->class->parts[0]), ['self', 'static', 'parent'], true)) {
                $instanceof_class = ClassLikeChecker::getFQCLNFromNameObject(
                    $stmt->class,
                    $source->getAliases()
                );

                return $instanceof_class;
            } elseif ($this_class_name
                && (in_array(strtolower($stmt->class->parts[0]), ['self', 'static'], true))
            ) {
                return $this_class_name;
            }
        }

        return null;
    }

    /**
     * @param   PhpParser\Node\Expr\BinaryOp    $conditional
     *
     * @return  int|null
     */
    protected static function hasNullVariable(PhpParser\Node\Expr\BinaryOp $conditional)
    {
        if ($conditional->right instanceof PhpParser\Node\Expr\ConstFetch
            && strtolower($conditional->right->name->parts[0]) === 'null'
        ) {
            return self::ASSIGNMENT_TO_RIGHT;
        }

        if ($conditional->left instanceof PhpParser\Node\Expr\ConstFetch
            && strtolower($conditional->left->name->parts[0]) === 'null'
        ) {
            return self::ASSIGNMENT_TO_LEFT;
        }

        return null;
    }

    /**
     * @param   PhpParser\Node\Expr\BinaryOp    $conditional
     *
     * @return  int|null
     */
    protected static function hasFalseVariable(PhpParser\Node\Expr\BinaryOp $conditional)
    {
        if ($conditional->right instanceof PhpParser\Node\Expr\ConstFetch
            && strtolower($conditional->right->name->parts[0]) === 'false'
        ) {
            return self::ASSIGNMENT_TO_RIGHT;
        }

        if ($conditional->left instanceof PhpParser\Node\Expr\ConstFetch
            && strtolower($conditional->left->name->parts[0]) === 'false'
        ) {
            return self::ASSIGNMENT_TO_LEFT;
        }

        return null;
    }

    /**
     * @param   PhpParser\Node\Expr\BinaryOp    $conditional
     *
     * @return  int|null
     */
    protected static function hasTrueVariable(PhpParser\Node\Expr\BinaryOp $conditional)
    {
        if ($conditional->right instanceof PhpParser\Node\Expr\ConstFetch
            && strtolower($conditional->right->name->parts[0]) === 'true'
        ) {
            return self::ASSIGNMENT_TO_RIGHT;
        }

        if ($conditional->left instanceof PhpParser\Node\Expr\ConstFetch
            && strtolower($conditional->left->name->parts[0]) === 'true'
        ) {
            return self::ASSIGNMENT_TO_LEFT;
        }

        return null;
    }

    /**
     * @param   PhpParser\Node\Expr\BinaryOp    $conditional
     *
     * @return  false|int
     */
    protected static function hasGetTypeCheck(PhpParser\Node\Expr\BinaryOp $conditional)
    {
        if ($conditional->right instanceof PhpParser\Node\Expr\FuncCall &&
            $conditional->right->name instanceof PhpParser\Node\Name &&
            strtolower($conditional->right->name->parts[0]) === 'gettype' &&
            $conditional->left instanceof PhpParser\Node\Scalar\String_) {
            return self::ASSIGNMENT_TO_RIGHT;
        }

        if ($conditional->left instanceof PhpParser\Node\Expr\FuncCall &&
            $conditional->left->name instanceof PhpParser\Node\Name &&
            strtolower($conditional->left->name->parts[0]) === 'gettype' &&
            $conditional->right instanceof PhpParser\Node\Scalar\String_) {
            return self::ASSIGNMENT_TO_LEFT;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\BinaryOp    $conditional
     *
     * @return  false|int
     */
    protected static function hasGetClassCheck(PhpParser\Node\Expr\BinaryOp $conditional)
    {
        $right_get_class = $conditional->right instanceof PhpParser\Node\Expr\FuncCall
            && $conditional->right->name instanceof PhpParser\Node\Name
            && strtolower($conditional->right->name->parts[0]) === 'get_class';

        $right_static_class = $conditional->right instanceof PhpParser\Node\Expr\ClassConstFetch
            && $conditional->right->class instanceof PhpParser\Node\Name
            && $conditional->right->class->parts === ['static']
            && is_string($conditional->right->name)
            && strtolower($conditional->right->name) === 'class';

        $left_class_string = $conditional->left instanceof PhpParser\Node\Scalar\String_
            || ($conditional->left instanceof PhpParser\Node\Expr\ClassConstFetch
                && $conditional->left->class instanceof PhpParser\Node\Name
                && is_string($conditional->left->name)
                && strtolower($conditional->left->name) === 'class');

        if (($right_get_class || $right_static_class) && $left_class_string) {
            return self::ASSIGNMENT_TO_RIGHT;
        }

        $left_get_class = $conditional->left instanceof PhpParser\Node\Expr\FuncCall
            && $conditional->left->name instanceof PhpParser\Node\Name
            && strtolower($conditional->left->name->parts[0]) === 'get_class';

        $left_static_class = $conditional->left instanceof PhpParser\Node\Expr\ClassConstFetch
            && $conditional->left->class instanceof PhpParser\Node\Name
            && $conditional->left->class->parts === ['static']
            && is_string($conditional->left->name)
            && strtolower($conditional->left->name) === 'class';

        $right_class_string = $conditional->right instanceof PhpParser\Node\Scalar\String_
            || ($conditional->right instanceof PhpParser\Node\Expr\ClassConstFetch
                && $conditional->right->class instanceof PhpParser\Node\Name
                && is_string($conditional->right->name)
                && strtolower($conditional->right->name) === 'class');

        if (($left_get_class || $left_static_class) && $right_class_string) {
            return self::ASSIGNMENT_TO_LEFT;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\BinaryOp    $conditional
     *
     * @return  false|int
     */
    protected static function hasTypedValueComparison(PhpParser\Node\Expr\BinaryOp $conditional)
    {
        if (isset($conditional->right->inferredType)
            && count($conditional->right->inferredType->getTypes()) === 1
        ) {
            return self::ASSIGNMENT_TO_RIGHT;
        }

        if (isset($conditional->left->inferredType)
            && count($conditional->left->inferredType->getTypes()) === 1
        ) {
            return self::ASSIGNMENT_TO_LEFT;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasNullCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && strtolower($stmt->name->parts[0]) === 'is_null') {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasIsACheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name
            && strtolower($stmt->name->parts[0]) === 'is_a'
            && isset($stmt->args[1])
        ) {
            $second_arg = $stmt->args[1]->value;

            if ($second_arg instanceof PhpParser\Node\Scalar\String_
                || (
                    $second_arg instanceof PhpParser\Node\Expr\ClassConstFetch
                    && $second_arg->class instanceof PhpParser\Node\Name
                    && is_string($second_arg->name)
                    && strtolower($second_arg->name) === 'class'
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasArrayCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && strtolower($stmt->name->parts[0]) === 'is_array') {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasStringCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && strtolower($stmt->name->parts[0]) === 'is_string') {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasBoolCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && strtolower($stmt->name->parts[0]) === 'is_bool') {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasObjectCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['is_object']) {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasNumericCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['is_numeric']) {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasIterableCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && strtolower($stmt->name->parts[0]) === 'is_iterable') {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasIntCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name &&
            ($stmt->name->parts === ['is_int'] ||
                $stmt->name->parts === ['is_integer'] ||
                $stmt->name->parts === ['is_long'])
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasFloatCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name &&
            ($stmt->name->parts === ['is_float'] ||
                $stmt->name->parts === ['is_real'] ||
                $stmt->name->parts === ['is_double'])
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasResourceCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['is_resource']) {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasScalarCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['is_scalar']) {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasCallableCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['is_callable']) {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasInArrayCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name
            && $stmt->name->parts === ['in_array']
            && isset($stmt->args[2])
        ) {
            $second_arg = $stmt->args[2]->value;

            if ($second_arg instanceof PhpParser\Node\Expr\ConstFetch
                && $second_arg->name instanceof PhpParser\Node\Name
                && strtolower($second_arg->name->parts[0]) === 'true'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasArrayKeyExistsCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['array_key_exists']) {
            return true;
        }

        return false;
    }
}
