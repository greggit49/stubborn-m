<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Product;
use Stripe\Stripe;
use Stripe\Checkout\Session as StripeSession;
use Symfony\Component\HttpFoundation\JsonResponse;


class CartController extends AbstractController

{
    private string $stripeSecretKey;
    private string $stripePublicKey;

    public function __construct(string $stripeSecretKey, string $stripePublicKey)
    {
        $this->stripeSecretKey = $stripeSecretKey;
        $this->stripePublicKey = $stripePublicKey;
    }

    #[Route('/cart', name: 'cart')]
    public function index(SessionInterface $session): Response
    {
        $cart = $session->get('cart', []);
        $total = array_sum(array_map(fn($item) => $item['price'] * $item['quantity'], $cart));

        return $this->render('cart/index.html.twig', [
            'cart' => $cart,
            'total' => $total,
            'stripe_public_key' => $this->stripePublicKey,
        ]);
    }

    #[Route('/cart/add/{id}', name: 'add_to_cart')]
    public function addToCart(SessionInterface $session, int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $cart = $session->get('cart', []);

        $product = $entityManager->getRepository(Product::class)->find($id);
        if (!$product) {
            throw $this->createNotFoundException('Produit non trouvé');
        }

        $size = $request->request->get('size', 'M');

        if (!isset($cart[$id])) {
            $cart[$id] = [
                'name' => $product->getName(),
                'price' => $product->getPrice(),
                'image' => $product->getImage(),
                'size' => $size,
                'quantity' => 1
            ];
        } else {
            $cart[$id]['quantity']++;
        }

        $session->set('cart', $cart);

        return $this->redirectToRoute('cart');
    }

    #[Route('/cart/remove/{id}', name: 'cart_remove')]
    public function removeFromCart(SessionInterface $session, int $id): Response
    {
        $cart = $session->get('cart', []);

        if (isset($cart[$id])) {
            unset($cart[$id]);
        }

        $session->set('cart', $cart);

        return $this->redirectToRoute('cart');
    }

    #[Route('/payment-stripe', name: 'payment_stripe', methods: ['POST'])]
    public function paymentStripe(SessionInterface $session): JsonResponse
    {
        Stripe::setApiKey($this->stripeSecretKey);

        $cart = $session->get('cart', []);
        $lineItems = [];

        foreach ($cart as $item) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => [
                        'name' => $item['name'],
                    ],
                    'unit_amount' => $item['price'] * 100,
                ],
                'quantity' => $item['quantity'],
            ];
        }

        $sessionStripe = StripeSession::create([
            'payment_method_types' => ['card'],
            'line_items' => $lineItems,
            'mode' => 'payment',
            'success_url' => $this->generateUrl('payment_success', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL),
            'cancel_url' => $this->generateUrl('cart', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL),
        ]);

        return new JsonResponse(['sessionId' => $sessionStripe->id]);
    }

    #[Route('/payment-success', name: 'payment_success')]
    public function paymentSuccess(): Response
    {
        return $this->render('cart/payment_success.html.twig', [
            'message' => 'Paiement réussi !',
        ]);
    }
}