<?php
declare(strict_types = 1);
namespace Habeuk\HabeukBaseTest;

use App\Entity\User;
use App\Entity\Files;
use App\Tests\Functional\Controller\Api\Entity\Modeles\UserModel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\UserInterface;

abstract class BaseFunctional extends WebTestCase {

  protected ?EntityManagerInterface $entityManager = null;

  protected ?KernelBrowser $client = null;

  protected ?UserPasswordHasherInterface $passwordHasher = null;

  private ?Files $file = null;

  protected function setUp(): void {
    self::ensureKernelShutdown();

    $this->client = static::createClient();

    $container = static::getContainer();

    $this->entityManager = $container->get(EntityManagerInterface::class);
    $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);
  }

  protected function tearDown(): void {
    $this->getEntityManager()->clear();
    parent::tearDown();
  }

  // ======================
  // SETUP DIRECT (EntityManager)
  // ======================
  protected function createUser(string $email): User {
    $entityManager = $this->getEntityManager();
    $oldUser = $entityManager->getRepository(User::class)->findOneBy([
      'email' => $email
    ]);
    if ($oldUser !== null) {
      return $oldUser;
    }
    $user = new User();
    $user->email = $email;
    $passwordH = $this->getPasswordHasher()->hashPassword($user, 'test-password');
    $user->setHashedPassword($passwordH);
    $user->setRoles([
      'ROLE_USER'
    ]);
    $entityManager->persist($user);
    $entityManager->flush();
    return $user;
  }

  protected function createTestImage(User $user, string $fileName = 'test-logo.png'): Files {
    if ($this->file === null) {
      $oldFile = $this->getEntityManager()
        ->getRepository(Files::class)
        ->findOneBy([
        'fileName' => $fileName
      ]);
      if ($oldFile !== null) {
        $this->file = $oldFile;
        return $this->file;
      }
      $file = new Files();
      $file->setFileName('test-logo.png');
      $file->setOriginalName('test-logo.png');
      $file->setFileSize(1024);
      $file->setMimeType('image/png');
      $file->setType('company_logo');
      $file->setDescription('Logo de test');
      $file->setUser($user);
      $this->getEntityManager()->persist($file);
      $this->getEntityManager()->flush();
      $this->file = $file;
    }
    return $this->file;
  }

  // ======================
  // ACCESSEURS
  // ======================
  protected function getEntityManager(): EntityManagerInterface {
    $entityManager = $this->entityManager;
    self::assertNotNull($entityManager);
    return $entityManager;
  }

  protected function getBrowser(): KernelBrowser {
    $client = $this->client;
    self::assertNotNull($client);
    return $client;
  }

  protected function resetBrowser(): KernelBrowser {
    $client = $this->getBrowser();
    $client->restart();
    return $client;
  }

  protected function login(User $user, ?string $password = null): void {
    if ($password === null) {
      $password = UserModel::DEFAULT_PASSWORD;
    }
    $token = $this->getJwtToken($user, $password);
    $client = $this->getBrowser();
    // On stocke le token pour les prochaines requêtes
    $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $token);
    $client->loginUser($user);
  }

  protected function logout(): void {
    $this->getBrowser()->setServerParameter('HTTP_AUTHORIZATION', '');
    $this->resetBrowser();
  }

  /**
   * Authentifie un utilisateur via l'endpoint de login JWT et retourne le token.
   */
  protected function getJwtToken(User $user, string $password): string {
    $this->jsonPost('/api/login_check', [
      'email' => $user->getEmail(),
      'password' => $password
    ]);
    self::assertResponseIsSuccessful();
    self::assertResponseHeaderSame('content-type', 'application/json');
    $content = $this->getBrowser()
      ->getResponse()
      ->getContent();
    self::assertNotFalse($content);
    $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    self::assertIsArray($data);
    self::assertArrayHasKey('token', $data);
    self::assertIsString($data['token']);
    self::assertNotEmpty($data['token']);
    return $data['token'];
  }

  protected function getLoggedInUser(): ?UserInterface {
    $token = $this->getBrowser()
      ->getContainer()
      ->get('security.token_storage')
      ->getToken();

    $user = $token?->getUser();

    return $user instanceof UserInterface ? $user : null;
  }

  protected function assertUserIsLoggedIn(?UserInterface $expectedUser = null): void {
    $user = $this->getLoggedInUser();

    self::assertNotNull($user, 'Aucun utilisateur n\'est connecté');

    if ($expectedUser !== null) {
      self::assertSame($expectedUser->getUserIdentifier(), $user->getUserIdentifier());
    }
  }

  protected function getPasswordHasher(): UserPasswordHasherInterface {
    $passwordHasher = $this->passwordHasher;
    self::assertNotNull($passwordHasher);
    return $passwordHasher;
  }

  // ======================
  // REQUÊTES HTTP
  // ======================

  /**
   *
   * @param string $url
   * @param array<mixed> $payload
   */
  protected function requestPost(string $url, array $payload): Response {
    $this->getBrowser()->request('POST', $url, server: [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_ACCEPT' => 'application/json'
    ], content: json_encode($payload, JSON_THROW_ON_ERROR));
    return $this->getBrowser()->getResponse();
  }

  /**
   *
   * @param string $url
   * @param array<string, mixed> $query
   */
  protected function requestGet(string $url, array $query = []): Response {
    $this->getBrowser()->request('GET', $url, parameters: $query, server: [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_ACCEPT' => 'application/json'
    ]);
    return $this->getBrowser()->getResponse();
  }

  /**
   *
   * @param string $url
   * @param array<string, mixed> $query
   * @return array<string, mixed>
   */
  protected function jsonGet(string $url, array $query = []): array {
    $this->requestGet($url, $query);
    return $this->getJsonResponse();
  }

  /**
   *
   * @param string $url
   * @param array<string, mixed> $payload
   * @return array<string, mixed>
   */
  protected function jsonPost(string $url, array $payload): array {
    $this->requestPost($url, $payload);
    return $this->getJsonResponse();
  }

  /**
   *
   * @return array<string, mixed>
   */
  protected function getJsonResponse(): array {
    $content = $this->getBrowser()
      ->getResponse()
      ->getContent();
    self::assertNotFalse($content, 'La réponse est vide');
    $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    self::assertIsArray($data);
    return $data;
  }

  /**
   * POST JSON sur l'API CRUD, vérifie le code HTTP attendu et retourne la
   * réponse décodée (tableau).
   *
   * @param array<mixed> $payload
   * @return array<mixed>
   */
  protected function apiPost(string $url, array $payload, int $expectedStatus = Response::HTTP_OK): array {
    $result = $this->requestPost($url, $payload)->getContent();
    self::assertResponseStatusCodeSame($expectedStatus);
    self::assertNotFalse($result);
    $data = json_decode($result, true);
    self::assertIsArray($data);
    return $data;
  }

  /**
   * Extrait l'id de la réponse JSON brute (format retourné par l'API CRUD).
   */
  protected function extractId(string|false $content): int {
    self::assertNotFalse($content);
    $responseData = json_decode($content, true);
    self::assertIsArray($responseData);
    return $this->extractIdFromData($responseData);
  }

  /**
   * Extrait l'id d'un tableau de réponse décodé.
   *
   * @param array<mixed> $responseData
   */
  protected function extractIdFromData(array $responseData): int {
    self::assertArrayHasKey('id', $responseData);
    return (int) $responseData['id'];
  }
}