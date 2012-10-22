<?php
namespace Payum\Tests;

use Payum\Payment;
use Payum\Action\ActionPaymentAware;
use Payum\Action\ActionInterface;
use Payum\Request\InteractiveRequestInterface;

class PaymentTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @test
     */
    public function shouldImplementPaymentInterface()
    {
        $rc = new \ReflectionClass('Payum\Payment');
        
        $this->assertTrue($rc->implementsInterface('Payum\PaymentInterface'));
    }

    /**
     * @test
     */
    public function couldBeConstructedWithoutAnyArguments()
    {
        new Payment();
    }

    /**
     * @test
     * 
     * @expectedException \Payum\Exception\RequestNotSupportedException
     */
    public function throwRequestNotSupportedIfNoneActionSet()
    {
        $request = new \stdClass();
        
        $payment = new Payment();
        
        $payment->execute($request);
    }

    /**
     * @test
     */
    public function shouldProxyRequestToActionWhichSupportsRequest()
    {
        $request = new \stdClass();
        
        $actionMock = $this->createActionMock();
        $actionMock
            ->expects($this->once())
            ->method('supports')
            ->with($request)
            ->will($this->returnValue(true))
        ;
        $actionMock
            ->expects($this->once())
            ->method('execute')
            ->with($request)
        ;
        
        $payment = new Payment();
        $payment->addAction($actionMock);

        $payment->execute($request);
    }

    /**
     * @test
     */
    public function shouldCatchInteractiveRequestThrownAndReturnItByDefault()
    {
        $expectedInteractiveRequest = $this->createInteractiveRequestMock();
        $request = new \stdClass();

        $actionMock = $this->createActionMock();
        $actionMock
            ->expects($this->once())
            ->method('supports')
            ->will($this->returnValue(true))
        ;
        $actionMock
            ->expects($this->once())
            ->method('execute')
            ->will($this->throwException($expectedInteractiveRequest))
        ;

        $payment = new Payment();
        $payment->addAction($actionMock);

        $actualInteractiveRequest = $payment->execute($request);
        
        $this->assertSame($expectedInteractiveRequest, $actualInteractiveRequest);
    }

    /**
     * @test
     */
    public function shouldCatchInteractiveRequestOnlyAtFirstRequestExecuteLevel()
    {
        $firstRequest = new \stdClass();
        $secondRequest = new \stdClass();
        $interactiveRequest = $this->createInteractiveRequestMock();
        
        $firstAction = new RequireOtherRequestAction();
        $firstAction->setSupportedRequest($firstRequest);
        $firstAction->setRequiredRequest($secondRequest);
        
        $secondAction = new ThrowInteractiveAction();
        $secondAction->setSupportedRequest($secondRequest);
        $secondAction->setInteractiveRequest($interactiveRequest);

        $payment = new Payment();
        $payment->addAction($firstAction);
        $payment->addAction($secondAction);

        $actualInteractiveRequest = $payment->execute($firstRequest);

        $this->assertSame($interactiveRequest, $actualInteractiveRequest);
    }

    /**
     * @test
     */
    public function shouldSetPaymentToActionIfActionAwareOfPayment()
    {
        $payment = new Payment();
        
        $actionMock = $this->createActionPaymentAwareMock();
        $actionMock
            ->expects($this->once())
            ->method('setPayment')
            ->with($this->isInstanceOf('Payum\Payment'))
        ;
        
        $payment->addAction($actionMock);
    }

    /**
     * @test
     */
    public function shouldSetFirstRequestPropertyToNullIfOnExceptionThrown()
    {
        $exception = new \LogicException('Test exception');
        
        $payment = new Payment();

        $actionMock = $this->createActionMock();
        $actionMock
            ->expects($this->once())
            ->method('execute')
            ->will($this->throwException($exception))
        ;
        $actionMock
            ->expects($this->once())
            ->method('supports')
            ->will($this->returnValue(true))
        ;

        $payment->addAction($actionMock);
        try {
            $payment->execute(new \stdClass());
            
            $this->fail('Expected LogicException to be thrown.');
        } catch (\Exception $e) {
            $this->assertAttributeEmpty('firstRequest', $payment);
        }
    }

    /**
     * @test
     * 
     * @expectedException \Payum\Exception\CycleRequestsException
     * @expectedExceptionMessage The action Payum\Tests\RequireOtherRequestAction is called 100 times. Possible requests infinite loop detected.
     */
    public function throwCycleRequestIfActionCallsMoreThenLimitAllows()
    {
        $cycledRequest = new \stdClass();
        
        $action = new RequireOtherRequestAction;
        $action->setSupportedRequest($cycledRequest);
        $action->setRequiredRequest($cycledRequest);
        
        $payment = new Payment();
        $payment->addAction($action);

        $payment->execute($cycledRequest);
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|\Payum\Request\InteractiveRequestInterface
     */
    protected function createInteractiveRequestMock()
    {
        return $this->getMock('Payum\Request\InteractiveRequest');
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|\Payum\Action\ActionPaymentAwareInterface
     */
    protected function createActionPaymentAwareMock()
    {
        return $this->getMock('Payum\Action\ActionPaymentAwareInterface');
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|\Payum\Action\ActionInterface
     */
    protected function createActionMock()
    {
        return $this->getMock('Payum\Action\ActionInterface');
    }
}

class RequireOtherRequestAction extends ActionPaymentAware
{
    protected $supportedRequest;

    protected $requiredRequest;

    /**
     * @param $request
     */
    public function setSupportedRequest($request)
    {
        $this->supportedRequest = $request;
    }

    /**
     * @param $request
     */
    public function setRequiredRequest($request)
    {
        $this->requiredRequest = $request;
    }

    public function execute($request)
    {
        $this->payment->execute($this->requiredRequest);
    }

    public function supports($request)
    {
        return $this->supportedRequest === $request;
    }
}

class ThrowInteractiveAction implements ActionInterface
{
    protected $supportedRequest;
    
    protected $interactiveRequest;
    
    /**
     * @param $request
     */
    public function setSupportedRequest($request)
    {
        $this->supportedRequest = $request;
    }

    /**
     * @param $request
     */
    public function setInteractiveRequest(InteractiveRequestInterface $request)
    {
        $this->interactiveRequest = $request;
    }
    
    public function execute($request)
    {
        throw $this->interactiveRequest;
    }

    public function supports($request)
    {
        return $this->supportedRequest === $request;
    }
}