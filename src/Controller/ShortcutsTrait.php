<?php declare(strict_types=1);

namespace Gmo\Web\Controller;

use Gmo\Web\Response\TemplateView;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Helper methods for controllers.
 *
 * Needs to be used on a class that implements {@see ContainerInterface}.
 *
 * @mixin ContainerInterface
 *
 * @author Carson Full <carsonfull@gmail.com>
 */
trait ShortcutsTrait
{
    /**
     * Shortcut for {@see UrlGeneratorInterface::generate}
     *
     * @param string $name          #Route The name of the route
     * @param array  $params        An array of parameters
     * @param int    $referenceType The type of reference to be generated (one of the constants)
     *
     * @return string
     */
    public function generateUrl(string $name, array $params = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        return $this->get('url_generator')->generate($name, $params, $referenceType);
    }

    /**
     * Redirects the user to another URL.
     *
     * @param string $url    The URL to redirect to
     * @param int    $status The status code (302 by default)
     *
     * @return RedirectResponse
     */
    public function redirect(string $url, int $status = 302): RedirectResponse
    {
        return new RedirectResponse($url, $status);
    }

    /**
     * Returns a RedirectResponse to the given route with the given parameters.
     *
     * @param string $route      #Route The name of the route
     * @param array  $parameters An array of parameters
     * @param int    $status     The status code to use for the Response
     *
     * @return RedirectResponse
     */
    public function redirectToRoute(string $route, array $parameters = [], int $status = 302): RedirectResponse
    {
        return $this->redirect($this->generateUrl($route, $parameters), $status);
    }

    /**
     * Convert some data into a JSON response.
     *
     * @param mixed $data    The response data
     * @param int   $status  The response status code
     * @param array $headers An array of response headers
     *
     * @return JsonResponse
     */
    public function json($data = [], int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers);
    }

    public function session(): SessionInterface
    {
        return $this->get('session');
    }

    public function flashes(): FlashBagInterface
    {
        $session = $this->session();
        if (method_exists($session, 'getFlashBag')) {
            return $session->getFlashBag();
        }

        throw new \LogicException('Session must have getFlashBag() method');
    }

    /**
     * Dispatches an event to all registered listeners.
     *
     * @param string $eventName The name of the event to dispatch. The name of the event is the name of the method that
     *                          is invoked on listeners.
     * @param Event  $event     The event to pass to the event handlers/listeners. If not supplied, an empty Event
     *                          instance is created.
     *
     * @return Event
     */
    public function dispatch(string $eventName, Event $event = null): Event
    {
        return $this->get('dispatcher')->dispatch($eventName, $event);
    }

    /**
     * Creates and returns a Form instance from the type of the form.
     *
     * @param string $type    #FormType The built type of the form
     * @param mixed  $data    The initial data for the form
     * @param array  $options Options for the form
     *
     * @return FormInterface
     */
    public function createForm(string $type = FormType::class, $data = null, array $options = []): FormInterface
    {
        return $this->get('form.factory')->create($type, $data, $options);
    }

    /**
     * Returns a form builder.
     *
     * @param string $type    #FormType The type of the form
     * @param mixed  $data    The initial data
     * @param array  $options The options
     *
     * @return FormBuilderInterface The form builder
     */
    public function createFormBuilder(string $type = FormType::class, $data = null, array $options = []): FormBuilderInterface
    {
        return $this->get('form.factory')->createBuilder($type, $data, $options);
    }

    /**
     * Returns a TemplateView.
     *
     * @param string   $template #Template name
     * @param iterable $context  Template context
     *
     * @return TemplateView
     */
    public function render(string $template, iterable $context = []): TemplateView
    {
        return new TemplateView($template, $context);
    }

    /**
     * Shortcut for creating a TemplateView with a form.
     *
     * @param FormInterface $form
     * @param string        $template #Template name
     * @param array         $context  Template context
     *
     * @return TemplateView
     */
    public function renderForm(FormInterface $form, string $template, array $context = []): TemplateView
    {
        $context['form'] = $form->createView();

        return $this->render($template, $context);
    }
}
