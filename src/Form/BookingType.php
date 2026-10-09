<?php

namespace Base\Restaurant\Form;

use Base\Form\Type\PrivacyType;
use Base\Restaurant\Model\Booking;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The public reservation form, on a model (Model\Booking): the day, how
 * many, the time among those offered (the page fills them as the day and
 * the size change), who, what they ask, and glitchr/omnibase's
 * data-protection notice. Guarded as glitchr/omnibase guards a form (its
 * option `guard`, action "book"): a trap, the time it takes, the lists, the
 * captcha when the site has glitchr/omnishield.
 */
class BookingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('day', DateType::class, ['label' => '@restaurant.book.day', 'widget' => 'single_text', 'input' => 'string', 'input_format' => 'Y-m-d', 'attr' => ['min' => $options['min'], 'max' => $options['max']]])
            ->add('covers', IntegerType::class, ['label' => '@restaurant.book.covers', 'attr' => ['min' => 1, 'max' => $options['max_covers']]])
            ->add('time', HiddenType::class)
            ->add('name', TextType::class, ['label' => '@restaurant.book.name', 'attr' => ['autocomplete' => 'name']])
            ->add('email', EmailType::class, ['label' => '@restaurant.book.email', 'attr' => ['autocomplete' => 'email']])
            ->add('phone', TelType::class, ['label' => '@restaurant.book.phone', 'attr' => ['autocomplete' => 'tel']])
            ->add('allergies', TextType::class, ['label' => '@restaurant.book.allergies', 'required' => false])
            ->add('notes', TextareaType::class, ['label' => '@restaurant.book.notes', 'required' => false, 'attr' => ['rows' => 3]])
            ->add('privacy', PrivacyType::class, [
                'notice' => '@restaurant.book.privacy',
                'consent' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Booking::class,
            'min' => null,
            'max' => null,
            'max_covers' => 8,
            'translation_domain' => 'restaurant',
            'guard' => ['action' => 'book'],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'booking';
    }
}
