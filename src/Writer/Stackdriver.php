<?php
namespace Laminas\Log\Writer;

use Laminas\Log\Writer\AbstractWriter;
use Google\Cloud\Logging\LoggingClient;
use Laminas\ServiceManager\ServiceManager;
use Google\Cloud\Core\Exception\GoogleException;
use Laminas\Log\Writer\Noop;
use Google\Cloud\Core\Report\MetadataProviderInterface;
use Psr\Http\Message\ServerRequestInterface;
use X\Amqp\Message;
use function X\Log\Writer\utfize;
use function X\Log\Writer\uuid;

/**
 * @property LoggingClient $client
 * @property \Google\Cloud\Logging\PsrLogger $stackdriver
 */
final class Stackdriver extends AbstractWriter implements MetadataProviderInterface
{
    public $stackdriver;
    private array $labels = [];

    private $options;

    static public function factory(ServiceManager $container, $name, $options = null)
    {
        $config = $container->get('config');
        $options = $config['log']['writers']['stackdriver'];

        $self = new static($options);
        $self->options = $options['options'];

        try {
            $self->stackdriver = $self->buildClient();
        } catch (GoogleException $e) {
            $self = new Noop();
        }

        return $self;
    }

    public function buildClient($logName = null)
    {
        $logName ??= $this->options['logName'];

        return LoggingClient::psrBatchLogger($logName, [
            'metadataProvider'      => $this,
            'clientConfig'          => $this->options,
            'debugOutput'           => true,
            'debugOutputResource'   => fopen('php://stderr', 'w')
        ]);
    }

    /**
     * Proxy method to enrich log messages in stackdriver.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $req
     */
    public function setRequest(ServerRequestInterface $req) : string
    {
        if ($req instanceof Message) {
            $this->alwaysExtra['amqpRequest'] = $req->getArrayCopy();
        }
        $this->setLabels(['appengine.googleapis.com/trace_id' => $trace = uuid()]);
        return $trace;
    }

    protected function doWrite($event)
    {
        switch ($event['priorityName']) {
            case 'EMERG': $severity = 'EMERGENCY'; break;
            case 'CRIT':  $severity = 'CRITICAL';  break;
            case 'ERR':   $severity = 'ERROR';     break;
            case 'WARN':  $severity = 'WARNING';   break;
            default: $severity = $event['priorityName'];
        }

        $context = [];
        if (isset($event['extra']['file'])) $context['context'] = [
            'reportLocation' => [
                'filePath' => $event['extra']['file'],
                'lineNumber' => $event['extra']['line']
            ]
        ];
        if (@is_array($event['extra']['trace'])) {
            $context['reportLocation']['functionName'] =
                $this->getFunctionNameForReport($event['extra']['trace']);
        } else {
            $context['reportLocation']['functionName'] = '<none>';
        }

        $context = array_merge($context, $event['extra']);
        $context = utfize($context);

        // exception
        if (isset($event['extra']['xdebug'])) {
            $this->stackdriver->log($severity, $event['extra']['xdebug'], $context);
        }
        // error
        else {
            $this->stackdriver->log($severity, $event['message'], $context);
        }
    }

    public function setLabels(array $labels)
    {
        $this->labels = $labels;
        return $this;
    }

    /**
     * Format the function name from a stack trace. This could be a global
     * function (function_name), a class function (Class->function), or a static
     * function (Class::function).
     *
     * @param array $trace The stack trace returned from Exception::getTrace()
     */
    public static function getFunctionNameForReport(array $trace = null)
    {
        if (null === $trace) {
            return '<unknown function>';
        }
        if (empty($trace[0]['function'])) {
            return '<none>';
        }
        $functionName = [$trace[0]['function']];
        if (isset($trace[0]['type'])) {
            $functionName[] = $trace[0]['type'];
        }
        if (isset($trace[0]['class'])) {
            $functionName[] = $trace[0]['class'];
        }
        return implode('', array_reverse($functionName));
    }

    /**
     * MetadataProvider adapter to google-logging-sdk
     * ==============================================
     */

    /**
     * Return an array representing MonitoredResource.
     * {@see https://cloud.google.com/logging/docs/reference/v2/rest/v2/MonitoredResource}
     *
     * @return array
     */
    public function monitoredResource()
    {
        return [
            'type' => 'generic_task',
            'labels' => [
                'project_id'    => $this->options['projectId'],
                'location'      => $_ENV['TENANT_ID'],
                'namespace'     => gethostname(),
                'job'           => $_ENV['SUPERVISOR_GROUP_NAME'],
                'task_id'       => $_ENV['SUPERVISOR_PROCESS_NAME'],
            ]
        ];
    }

    /**
     * Return the project id.
     * @return string
     */
    public function projectId()
    {
        return $this->options['projectId'];
    }

    /**
     * Return the service id.
     * @return string
     */
    public function serviceId()
    {
        return gethostname();
    }

    /**
     * Return the version id.
     * @return string
     */
    public function versionId()
    {
        return '1.0';
    }

    public function labels()
    {
        return $this->labels;
    }
}
