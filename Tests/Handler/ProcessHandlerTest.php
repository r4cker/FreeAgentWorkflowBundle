<?php

namespace FreeAgent\WorkflowBundle\Tests\Handler;

use Symfony\Component\EventDispatcher\EventDispatcher;

use FreeAgent\WorkflowBundle\Flow\NextStateInterface;
use FreeAgent\WorkflowBundle\Flow\Process;
use FreeAgent\WorkflowBundle\Flow\Step;
use FreeAgent\WorkflowBundle\Handler\ProcessHandler;
use FreeAgent\WorkflowBundle\Model\ModelStorage;
use FreeAgent\WorkflowBundle\Entity\ModelState;
use FreeAgent\WorkflowBundle\Exception\ValidationException;
use FreeAgent\WorkflowBundle\Tests\TestCase;
use FreeAgent\WorkflowBundle\Tests\Fixtures\FakeProcessListener;
Use FreeAgent\WorkflowBundle\Tests\Fixtures\FakeModel;
use FreeAgent\WorkflowBundle\Tests\Fixtures\FakeSecurityContext;
use FreeAgent\WorkflowBundle\Tests\Fixtures\FakeValidator;
use FreeAgent\WorkflowBundle\Tests\Fixtures\FakeAction;

class ProcessHandlerTest extends TestCase
{
    /**
     * @var Doctrine\ORM\EntityManager
     */
    protected $em;

    /**
     * @var FreeAgent\WorkflowBundle\Model\ModelStorage
     */
    protected $modelStorage;

    protected function setUp()
    {
        parent::setUp();

        $this->em = $this->getMockSqliteEntityManager();
        $this->createSchema($this->em);

        $this->modelStorage = new ModelStorage($this->em, 'FreeAgent\WorkflowBundle\Entity\ModelState');
    }

    public function testStart()
    {
        $model = new FakeModel();
        $modelState = $this->getProcessHandler()->start($model);

        $this->assertTrue($modelState instanceof ModelState);
        $this->assertEquals($model->getWorkflowIdentifier(), $modelState->getWorkflowIdentifier());
        $this->assertEquals('document_proccess', $modelState->getProcessName());
        $this->assertEquals('step_create_doc', $modelState->getStepName());
        $this->assertTrue($modelState->getCreatedAt() instanceof \DateTime);
        $this->assertTrue(is_array($modelState->getData()));
        $this->assertEquals(0, count($modelState->getData()));
        $this->assertEquals(FakeModel::STATUS_CREATE, $model->getStatus());
    }

    public function testStartWithData()
    {
        $data = array('some', 'informations');

        $model = new FakeModel();
        $model->data = $data;
        $modelState = $this->getProcessHandler()->start($model);

        $this->assertEquals($data, $modelState->getData());
    }

    /**
     * @expectedException        FreeAgent\WorkflowBundle\Exception\WorkflowException
     * @expectedExceptionMessage The given model has already started the "document_proccess" process.
     */
    public function testStartAlreadyStarted()
    {
        $model = new FakeModel();
        $this->modelStorage->newModelStateSuccess($model, 'document_proccess', 'step_create_doc');

        $this->getProcessHandler()->start($model);
    }

    /**
     * @expectedException        FreeAgent\WorkflowBundle\Exception\WorkflowException
     * @expectedExceptionMessage The given model has not started the "document_proccess" process.
     */
    public function testReachNextStateNotStarted()
    {
        $model = new FakeModel();

        $this->getProcessHandler()->reachNextState($model, 'validate');
    }

    public function testReachNextState()
    {
        $model = new FakeModel();
        $previous = $this->modelStorage->newModelStateSuccess($model, 'document_proccess', 'step_create_doc');

        $modelState = $this->getProcessHandler()->reachNextState($model, 'validate');

        $this->assertTrue($modelState instanceof ModelState);
        $this->assertEquals('step_validate_doc', $modelState->getStepName());
        $this->assertTrue($modelState->getSuccessful());
        $this->assertTrue($modelState->getPrevious() instanceof ModelState);
        $this->assertEquals($previous->getId(), $modelState->getPrevious()->getId());
        $this->assertEquals(FakeModel::STATUS_VALIDATE, $model->getStatus());
    }

    /**
     * @expectedException        FreeAgent\WorkflowBundle\Exception\WorkflowException
     * @expectedExceptionMessage The step "step_create_doc" does not contain any next state named "step_fake".
     */
    public function testReachNextStateInvalidNextStep()
    {
        $model = new FakeModel();
        $this->modelStorage->newModelStateSuccess($model, 'document_proccess', 'step_create_doc');

        $modelState = $this->getProcessHandler()->reachNextState($model, 'step_fake');
    }

    public function testReachNextStateWithListener()
    {
        $this->assertEquals(0, FakeProcessListener::$call);

        $reflectionClass = new \ReflectionClass('FreeAgent\WorkflowBundle\Handler\ProcessHandler');
        $method = $reflectionClass->getMethod('reachStep');
        $method->setAccessible(true);
        $method->invoke($this->getProcessHandler(), new FakeModel(), new Step('step_fake', 'Fake'));

        $this->assertEquals(1, FakeProcessListener::$call);
    }

    public function testReachNextStateError()
    {
        $model = new FakeModel();
        $this->modelStorage->newModelStateSuccess($model, 'document_proccess', 'step_create_doc');

        $modelState = $this->getProcessHandler()->reachNextState($model, 'remove');

        $this->assertEquals('step_fake', $modelState->getStepName());
    }

    public function testExecuteValidations()
    {
        $processHandler = $this->getProcessHandler();
        $step = new Step('sample', 'Sample', array());

        $reflectionClass = new \ReflectionClass('FreeAgent\WorkflowBundle\Flow\Step');
        $property = $reflectionClass->getProperty('validations');
        $property->setAccessible(true);
        $property->setValue($step, array(
            array(new FakeValidator(), 'valid'),
        ));

        $reflectionClass = new \ReflectionClass('FreeAgent\WorkflowBundle\Handler\ProcessHandler');
        $method = $reflectionClass->getMethod('executeValidations');
        $method->setAccessible(true);
        $validationViolations = $method->invoke($processHandler, new FakeModel(), $step->getValidations());

        $this->assertTrue(is_array($validationViolations));
        $this->assertEquals(0, count($validationViolations));

        $property->setValue($step, array(
            array(new FakeValidator(), 'invalid'),
        ));

        $validationViolations = $method->invoke($processHandler, new FakeModel(), $step->getValidations());

        $this->assertEquals(1, count($validationViolations));
        $this->assertTrue($validationViolations[0] instanceof ValidationException);
        $this->assertEquals('Validator error!', $validationViolations[0]->getMessage());
    }

    /**
     * @expectedException        FreeAgent\WorkflowBundle\Exception\WorkflowException
     * @expectedExceptionMessage Can't find step named "step_unknow" in process "document_proccess".
     */
    public function testGetProcessStepInvalidStepName()
    {
        $reflectionClass = new \ReflectionClass('FreeAgent\WorkflowBundle\Handler\ProcessHandler');
        $method = $reflectionClass->getMethod('getProcessStep');
        $method->setAccessible(true);
        $method->invoke($this->getProcessHandler(), 'step_unknow');
    }

    protected function getProcessHandler()
    {
        $stepValidateDoc = new Step(
            'step_validate_doc',
            'Validate doc',
            array(),
            array(),
            array('setStatus', 'FreeAgent\WorkflowBundle\Tests\Fixtures\FakeModel::STATUS_VALIDATE')
        );

        $stepRemoveDoc = new Step(
            'step_remove_doc',
            'Remove doc',
            array(),
            array(array(new FakeValidator(), 'invalid')),
            array('setStatus', 'FreeAgent\WorkflowBundle\Tests\Fixtures\FakeModel::STATUS_REMOVE'),
            array(),
            'step_fake'
        );

        $stepFake = new Step('step_fake', 'Fake', array());

        $stepCreateDoc = new Step(
            'step_create_doc',
            'Create doc',
            array(),
            array(),
            array('setStatus', 'FreeAgent\WorkflowBundle\Tests\Fixtures\FakeModel::STATUS_CREATE')
        );
        $stepCreateDoc->addNextState('validate', NextStateInterface::TARGET_TYPE_STEP, $stepValidateDoc);
        $stepCreateDoc->addNextState('remove', NextStateInterface::TARGET_TYPE_STEP, $stepRemoveDoc);

        $process = new Process(
            'document_proccess',
            array(
                'step_create_doc'   => $stepCreateDoc,
                'step_validate_doc' => $stepValidateDoc,
                'step_remove_doc'   => $stepRemoveDoc,
                'step_fake'         => $stepFake,
            ),
            'step_create_doc',
            array('step_validate_doc')
        );

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener('document_proccess.step_fake.reached', array(new FakeProcessListener(), 'handleSucccess'));

        $processHandler = new ProcessHandler($process, $this->modelStorage, $dispatcher);
        $processHandler->setSecurityContext(new FakeSecurityContext());

        return $processHandler;
    }
}
