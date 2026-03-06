<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
  name: 'app:grant-admin',
  description: 'Accorde le rôle ADMIN à un utilisateur',
)]
class GrantAdminRoleCommand extends Command
{
  public function __construct(
    private EntityManagerInterface $entityManager
  ) {
    parent::__construct();
  }

  protected function configure(): void
  {
    $this
      ->addArgument('email', InputArgument::REQUIRED, 'Email de l\'utilisateur');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $io = new SymfonyStyle($input, $output);
    $email = $input->getArgument('email');

    $user = $this->entityManager
      ->getRepository(User::class)
      ->findOneBy(['email' => $email]);

    if (!$user) {
      $io->error('Aucun utilisateur trouvé avec l\'email : ' . $email);
      return Command::FAILURE;
    }

    // Ajouter ROLE_ADMIN aux rôles existants
    $roles = $user->getRoles();
    if (!in_array('ROLE_ADMIN', $roles)) {
      $roles[] = 'ROLE_ADMIN';
      $user->setRoles($roles);
      $this->entityManager->flush();
      $io->success('Le rôle ADMIN a été accordé à : ' . $email);
    } else {
      $io->warning('L\'utilisateur : ' . $email . ' a déjà le rôle ADMIN');
    }

    return Command::SUCCESS;
  }
}

