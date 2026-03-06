<?php

namespace App\Controller;

use App\Entity\RendezVous;
use App\Form\RendezVousType;
use App\Repository\RendezVousRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/rendez/vous')]
final class RendezVousController extends AbstractController
{
    #[Route(name: 'app_rendez_vous_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(RendezVousRepository $rendezVousRepository): Response
    {
        // Si admin, voir tous les rendez-vous
        if ($this->isGranted('ROLE_ADMIN')) {
            $rendezVous = $rendezVousRepository->findAll();
        } else {
            // Sinon, voir uniquement ses propres rendez-vous
            $rendezVous = $rendezVousRepository->findBy(['user' => $this->getUser()]);
        }

        return $this->render('rendez_vous/index.html.twig', [
            'rendez_vouses' => $rendezVous,
        ]);
    }

    #[Route('/new', name: 'app_rendez_vous_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request, EntityManagerInterface $entityManager, RendezVousRepository $rendezVousRepository): Response
    {
        $rendezVou = new RendezVous();

        // Associer automatiquement l'utilisateur connecté au rendez-vous
        $rendezVou->setUser($this->getUser());

        // Définir le statut par défaut à "en attente"
        $rendezVou->setStatut('en attente');

        $form = $this->createForm(RendezVousType::class, $rendezVou);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Vérifier que le rendez-vous est dans les horaires du salon (09:00 - 18:00)
            $horsHoraire = $this->checkHeureSalon($rendezVou);

            if ($horsHoraire) {
                $this->addFlash('error', 'Le salon est ouvert de 09:00 à 18:00. Veuillez choisir un créneau horaire dans ces horaires.');
            } else {
                // Vérifier le chevauchement avec tous les rendez-vous (sauf annulés)
                $chevauchement = $this->checkChevauchement($rendezVou, $rendezVousRepository, null);

                if ($chevauchement) {
                    $this->addFlash('error', 'Ce créneau horaire est déjà réservé. Veuillez choisir une autre heure.');
                } else {
                    $entityManager->persist($rendezVou);
                    $entityManager->flush();

                    return $this->redirectToRoute('app_rendez_vous_index', [], Response::HTTP_SEE_OTHER);
                }
            }
        }

        return $this->render('rendez_vous/new.html.twig', [
            'rendez_vou' => $rendezVou,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_rendez_vous_show', methods: ['GET'])]
    public function show(RendezVous $rendezVou): Response
    {
        // Vérifier l'accès : admin ou propriétaire du rendez-vous
        if (!$this->isGranted('ROLE_ADMIN') && $rendezVou->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas voir ce rendez-vous.');
        }

        return $this->render('rendez_vous/show.html.twig', [
            'rendez_vou' => $rendezVou,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_rendez_vous_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, RendezVous $rendezVou, EntityManagerInterface $entityManager, RendezVousRepository $rendezVousRepository): Response
    {
        // Vérifier que l'utilisateur peut modifier ce rendez-vous (admin ou propriétaire)
        if (!$this->isGranted('ROLE_ADMIN') && $rendezVou->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas modifier ce rendez-vous.');
        }

        $form = $this->createForm(RendezVousType::class, $rendezVou);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Vérifier que le rendez-vous est dans les horaires du salon (09:00 - 18:00)
            $horsHoraire = $this->checkHeureSalon($rendezVou);

            if ($horsHoraire) {
                $this->addFlash('error', 'Le salon est ouvert de 09:00 à 18:00. Veuillez choisir un créneau horaire dans ces horaires.');
            } else {
                // Vérifier le chevauchement en excluant le rendez-vous actuel
                $chevauchement = $this->checkChevauchement($rendezVou, $rendezVousRepository, $rendezVou->getId());

                if ($chevauchement) {
                    $this->addFlash('error', 'Ce créneau horaire est déjà réservé. Veuillez choisir une autre heure.');
                } else {
                    $entityManager->flush();

                    return $this->redirectToRoute('app_rendez_vous_index', [], Response::HTTP_SEE_OTHER);
                }
            }
        }

        return $this->render('rendez_vous/edit.html.twig', [
            'rendez_vou' => $rendezVou,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/statut', name: 'app_rendez_vous_statut', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function changerStatut(Request $request, RendezVous $rendezVou, EntityManagerInterface $entityManager): Response
    {
        // Véririfer le token CSRF
        if (!$this->isCsrfTokenValid('statut' . $rendezVou->getId(), $request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $nouveauStatut = $request->request->get('statut');

        // Liste des statuts valides
        $statutsValides = ['en attente', 'accepté', 'terminé', 'annulé'];

        if (!in_array($nouveauStatut, $statutsValides, true)) {
            $this->addFlash('error', 'Statut invalide.');
            return $this->redirectToRoute('app_rendez_vous_index', [], Response::HTTP_SEE_OTHER);
        }

        $rendezVou->setStatut($nouveauStatut);
        $entityManager->flush();

        $this->addFlash('success', 'Le statut a été modifié avec succès.');

        return $this->redirectToRoute('app_rendez_vous_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}', name: 'app_rendez_vous_delete', methods: ['POST'])]
    public function delete(Request $request, RendezVous $rendezVou, EntityManagerInterface $entityManager): Response
    {
        // Vérifier que l'utilisateur peut supprimer ce rendez-vous (admin ou propriétaire)
        if (!$this->isGranted('ROLE_ADMIN') && $rendezVou->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas supprimer ce rendez-vous.');
        }

        if ($this->isCsrfTokenValid('delete' . $rendezVou->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($rendezVou);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_rendez_vous_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Vérifie si un créneau horaire chevauche un rendez-vous existant
     */
    private function checkChevauchement(RendezVous $rendezVou, RendezVousRepository $repository, ?int $excludeId): bool
    {
        // Récupérer la prestation et calculer l'heure de fin
        $prestation = $rendezVou->getPrestation();
        $dateDebut = $rendezVou->getDateHeure();
        $dateFin = (clone $dateDebut)->modify('+' . $prestation->getDuree() . ' minutes');

        // Récupérer tous les rendez-vous (sauf annulés et sauf celui exclu en modification)
        $rendezVousExistants = $repository->findAll();

        foreach ($rendezVousExistants as $rdv) {
            // Exclure le rendez-vous lui-même (en modification)
            if ($excludeId !== null && $rdv->getId() === $excludeId) {
                continue;
            }

            // Ignorer les rendez-vous annulés
            if ($rdv->getStatut() === 'annulé') {
                continue;
            }

            $rdvDebut = $rdv->getDateHeure();
            $rdvFin = (clone $rdvDebut)->modify('+' . $rdv->getPrestation()->getDuree() . ' minutes');

            // Vérifier si les créneaux se chevauchent
            if ($dateDebut < $rdvFin && $dateFin > $rdvDebut) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vérifie si le rendez-vous est dans les horaires du salon
     * Horaires : 09:00 - 18:00
     * 
     * @return bool true si hors horaires, false si valide
     */
    private function checkHeureSalon(RendezVous $rendezVou): bool
    {
        // Récupérer l'heure de début du rendez-vous
        $dateDebut = $rendezVou->getDateHeure();

        // Récupérer la durée de la prestation en minutes
        $prestation = $rendezVou->getPrestation();
        $dureeMinutes = $prestation->getDuree();

        // Calculer l'heure de fin
        $dateFin = (clone $dateDebut)->modify('+' . $dureeMinutes . ' minutes');

        // Définir les horaires du salon
        $heureOuverture = 9;  // 09:00
        $heureFermeture = 18; // 18:00

        // Vérifier si le rendez-vous commence après l'ouverture
        $heureDebut = (int) $dateDebut->format('H');
        if ($heureDebut < $heureOuverture) {
            return true; // Hors horaires
        }

        // Vérifier si le rendez-vous finit avant la fermeture
        $heureFin = (int) $dateFin->format('H');
        $minuteFin = (int) $dateFin->format('i');

        // Si l'heure de fin est après la fermeture
        if ($heureFin > $heureFermeture) {
            return true; // Hors horaires
        }

        // Si l'heure de fin est exactement à la fermeture, vérifier les minutes
        if ($heureFin === $heureFermeture && $minuteFin > 0) {
            return true; // Hors horaires
        }

        return false; // Dans les horaires
    }
}

