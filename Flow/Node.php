<?php

namespace FreeAgent\WorkflowBundle\Flow;

/**
 * Workflow node.
 *
 * @author Cédric Girard <c.girard@lexik.fr>
 */
abstract class Node
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var array
     */
    protected $validations;

    /**
     * @var array
     */
    protected $nextStates;

    /**
     * Constructor.
     *
     * @param string $name
     */
    public function __construct($name, array $nextStates = array(), array $validations = array())
    {
        $this->name        = $name;
        $this->validations = $validations;
        $this->nextStates  = $nextStates;
    }

    /**
     * Return the node name.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Returns all validations to execute to check if the step is reachable.
     *
     * @return array
     */
    public function getValidations()
    {
        return $this->validations;
    }

    /**
     * Returns true if the step requires some validations to be reached.
     *
     * @return boolean
     */
    public function hasValidations()
    {
        return !empty($this->validations);
    }

    /**
     * Returns all next steps.
     *
     * @return array
     */
    public function getNextStates()
    {
        return $this->nextStates;
    }

    /**
     * Returns true if the given step name is one of the next steps.
     *
     * @param string $name
     * @return boolean
     */
    public function hasNextState($name)
    {
        return in_array($name, array_keys($this->nextStates));
    }

    /**
     * Returns the target of the given state.
     *
     * @param string $name
     * @return NextStateInterface
     */
    public function getNextState($name)
    {
        if ( !$this->hasNextState($name) ) {
            return null;
        }

        return $this->nextStates[$name];
    }

    /**
     * Add a next state.
     *
     * @param string $name
     * @param string $targetType
     * @param Node   $state
     * @param array  $validations
     */
    public function addNextState($name, $targetType, Node $target, array $validations = array())
    {
        $this->nextStates[$name] = new NextState($name, $targetType, $target, $validations);
    }
}
