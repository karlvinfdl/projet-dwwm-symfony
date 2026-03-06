<?php

namespace App\Controller;

use App\Repository\RendezVousRepository;
use App\Repository\PrestationRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
  #[Route('/dashboard', name: 'app_admin_dashboard')]
  public function dashboard(
    RendezVousRepository $rendezVousRepository,
    PrestationRepository $prestationRepository,
    UserRepository $userRepository
  ): Response {
    // Statistiques
    $totalRendezVous = count($rendezVousRepository->findAll());
    $rendezVousEnAttente = count($rendezVousRepository->findBy(['Statut' => 'en attente']));
    $totalPrestations = count($prestationRepository->findAll());
    $totalUtilisateurs = count($userRepository->findAll());

    // Derniers rendez-vous (les 10 plus récents)
    $derniersRendezVous = $rendezVousRepository->findBy(
      [],
      ['DateHeure' => 'DESC'],
      10
    );

    return $this->render('admin/dashboard.html.twig', [
      'totalRendezVous' => $totalRendezVous,
      'rendezVousEnAttente' => $rendezVousEnAttente,
      'totalPrestations' => $totalPrestations,
      'totalUtilisateurs' => $totalUtilisateurs,
      'derniersRendezVous' => $derniersRendezVous,
    ]);
  }

  #[Route('/', name: 'app_admin_index')]
  public function index(): Response
  {
    // Redirection vers le dashboard
    return $this->redirectToRoute('app_admin_dashboard');
  }
}

