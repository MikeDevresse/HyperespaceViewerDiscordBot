<?php

namespace App\Controller;

use App\Form\StudentFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class StudentController extends AbstractController
{
    #[Route('/', name: 'app_student')]
    public function index(EntityManagerInterface $em, Request $request): Response
    {
        $form = $this->createForm(StudentFormType::class)->handleRequest($request);
        if($form->isSubmitted() && $form->isValid()) {
            $student = $form->getData();
            $em->persist($student);
            $em->flush();

            $this->addFlash('success',sprintf('Student %s added',$student->getLogin()));

            return $this->redirectToRoute('app_student');
        }
        return $this->renderForm(
            'student/new.html.twig',
            [
                'form' => $form,
            ]
        );
    }
}
