<?php

namespace app\controllers\course;

use app\controllers\AbstractController;
use app\controllers\admin\ConfigurationController;
use app\libraries\response\RedirectResponse;
use Symfony\Component\Routing\Annotation\Route;
use app\models\Email;

class CourseRegistrationController extends AbstractController {
    /**
     * @param array<string> $instructors
     */
    private function notifyInstructors(string $user, string $term, string $course, array $instructors): void {
        $subject = "Self-registration of $user for course $course";
        $body = "Student $user has self-registered for course $course for term $term.";
        $emails = [];
        foreach ($instructors as $instructor) {
            $emails[] = new Email(
                $this->core,
                [
                    "subject" => $subject,
                    "body" => $body,
                    "to_user_id" => $instructor
                ]
            );
        }

        $this->core->getNotificationFactory()->sendEmails($emails);
    }

    #[Route("/courses/{term}/{course}/alert_redirect")]
    public function alertRedirect(string $term, string $course): Response|RedirectResponse {
        $this->core->loadCourseConfig($term, $course);
        $this->core->loadCourseDatabase();
    
        // prevent URL tampering by ensuring the user came from selfUnregister()
        if (!$this->core->getSession()->get('unregister_confirmed')) {
            $this->core->addErrorMessage('Invalid request. Please try again.');
            return new RedirectResponse($this->core->buildUrl(['home']));
        }
    
        // if user confirmed, unregister
        if (isset($_GET['confirmed']) && $_GET['confirmed'] === 'true') {
            // Remove confirmation flag after checking
            $this->core->getSession()->remove('unregister_confirmed');
    
            // unregister user from course
            $this->unregisterCourseUser($term, $course);
            $this->core->addSuccessMessage('You have successfully unregistered from the course.');
    
            return new RedirectResponse($this->core->buildUrl(['home']));
        }
    
        // else render the JavaScript confirmation popup
        return new Response($this->core->getTwig()->render('courses/unregister_confirm.js.twig', [
            'confirm_url' => $this->core->buildUrl(['courses', $term, $course, 'alert_redirect'], ['confirmed' => 'true']),
            'cancel_url' => $this->core->buildCourseUrl()
        ]));
    }
    
    #[Route("/courses/{term}/{course}/register")]
    public function selfRegister(string $term, string $course): RedirectResponse {
        $this->core->loadCourseConfig($term, $course);
        $this->core->loadCourseDatabase();
        if ($this->core->getQueries()->getSelfRegistrationType($term, $course) === ConfigurationController::NO_SELF_REGISTER) {
            $this->core->addErrorMessage('Self registration is not allowed.');
            return new RedirectResponse($this->core->buildUrl(['home']));
        }
        else {
            $this->registerCourseUser($term, $course);
            return new RedirectResponse($this->core->buildCourseUrl());
        }
    }

    #[Route("/courses/{term}/{course}/unregister_from_course", name: "course_unregister")]
    public function selfUnregister(string $term, string $course): RedirectResponse {
        $this->core->loadCourseConfig($term, $course);
        $this->core->loadCourseDatabase();
    
        if ($this->core->getQueries()->getSelfRegistrationType($term, $course) === 0) {
            $this->core->addErrorMessage('You cannot unregister from this course on your own.');
            return new RedirectResponse($this->core->buildUrl(['home']));
        }
    
        // set confirmation flag to prevent direct access to alertRedirect
        $this->core->getSession()->set('unregister_confirmed', true);
    
        // redirect to alert route
        return new RedirectResponse($this->core->buildUrl(['courses', $term, $course, 'alert_redirect']));
    }

    public function registerCourseUser(string $term, string $course): void {
        $default_section = $this->core->getQueries()->getDefaultRegistrationSection($term, $course);
        $this->core->getUser()->setRegistrationSection($default_section);
        $this->core->getQueries()->insertCourseUser($this->core->getUser(), $term, $course);
        $instructor_ids = $this->core->getQueries()->getActiveUserIds(true, false, false, false, false);
        $this->notifyInstructors($this->core->getUser()->getId(), $term, $course, $instructor_ids);
    }

    public function unregisterCourseUser(string $term, string $course): void {
        $user = $this->core->getUser();
    
        // rm from course_users
        $this->core->getQueries()->unregisterCourseUser($user, $term, $course);
    
        // notify instructors
        $instructor_ids = $this->core->getQueries()->getActiveUserIds(true, false, false, false, false);
        $this->notifyInstructors($user->getId(), $term, $course, $instructor_ids);
    }
    
}
