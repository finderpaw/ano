<?php
session_start();
$loggedIn = !empty($_SESSION['logged_in']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Contact Us - PAWFINDER Community Pet System</title>

    <link rel="stylesheet" href="css/homepage.css">
    <link rel="stylesheet" href="css/contact.css">
    <!-- Dark mode overrides (must come after the other styles) -->
    <link rel="stylesheet" href="css/theme.css">
</head>

<body>
    <!-- Applies the saved dark mode (must be right after the opening body tag) -->
    <script src="js/darkmode.js"></script>

    <!-- SHARED HEADER -->
    <?php include 'header.php'; ?>


    <main class="content-area">

        <!-- HERO BANNER -->
        <div class="hero-banner hero-photos"
             style="background-image: url('image/casting-Picture-Background-low-res.webp');">

            <div class="hero-photo-overlay"></div>

            <div class="hero-photo-content">

                <h1>Contact Pawfinder</h1>

                <p>
                    Questions, rescue concerns, or want to partner with us?
                    We're here to help your community's pets.
                </p>

            </div>

        </div>


        <!-- SOCIAL MEDIA -->
        <?php
        $social_links = [
            [
                'name'   => 'Facebook',
                'handle' => '@pawfinderph',
                'desc'   => 'Rescue updates, adoption posts, and community announcements.',
                'url'    => 'https://www.facebook.com/pawfinder.2026?rdid=cMdk1qXnpKwGy4vS&share_url=https%3A%2F%2Fwww.facebook.com%2Fshare%2F1F2QtntjgH',
                'badge'  => 'f',
                'class'  => 'social-facebook',
            ],
            [
                'name'   => 'Instagram',
                'handle' => '@pawfinderph',
                'desc'   => 'Photos of pets looking for homes and happy adoption stories.',
                'url'    => 'https://www.instagram.com/finderpaw.official/?fbclid=IwY2xjawVoHdtleHRuA2FlbQIxMABwZG9mBWJyaWQRMXpTVExuQWRaYTlLZ20yaUpzcnRjBmFwcF9pZBAyMjIwMzkxNzg4MjAwODkyAAEfDtnWO2darakK5RkOL6H2nBxgyTHy2WaGxbf6tbTnMZ9fUs8nSApwrLS9rN0_aem_AG5ubT6bn2QJENssrRBHFg',
                'badge'  => 'IG',
                'class'  => 'social-instagram',
            ],
            [
                'name'   => 'TikTok',
                'handle' => '@pawfindertiktok',
                'desc'   => 'Short rescue stories and tips on caring for your pets.',
                'url'    => 'https://www.tiktok.com/@pawfindertiktok?_r=1&_t=ZS-9BSV1X4FHcX&fbclid=IwY2xjawVoHyNleHRuA2FlbQIxMABwZG9mBWJyaWQRMXpTVExuQWRaYTlLZ20yaUpzcnRjBmFwcF9pZBAyMjIwMzkxNzg4MjAwODkyAAEfLjdUIY-YSkMNh7GJn7ZRje2lKIJqMdkoq2innIFhxV59uLZf_osjuzxmSJk_aem_Ktaefuw2UcRORZy5Mc6PnQ',
                'badge'  => 'TT',
                'class'  => 'social-tiktok',
            ],
        ];
        ?>


        <section class="partners-section" id="social-section">

            <div class="partners-header">

                <h2>Follow Pawfinder</h2>

                <p>
                    Stay connected with our community. Follow us on social media for the latest rescues,
                    pets up for adoption, and free health drive announcements in your barangay.
                </p>

            </div>


            <div class="social-cards-grid">

                <?php foreach ($social_links as $social): ?>

                    <a
                        href="<?= htmlspecialchars($social['url']) ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="social-card"
                    >

                        <span class="social-badge <?= htmlspecialchars($social['class']) ?>">
                            <?= htmlspecialchars($social['badge']) ?>
                        </span>

                        <div class="social-text">

                            <div class="shelter-name">
                                <?= htmlspecialchars($social['name']) ?>
                            </div>

                            <div class="shelter-meta">
                                <?= htmlspecialchars($social['handle']) ?>
                            </div>

                            <div class="social-desc">
                                <?= htmlspecialchars($social['desc']) ?>
                            </div>

                        </div>

                    </a>

                <?php endforeach; ?>

            </div>

        </section>


        <!-- CONTACT FORM -->

        <section class="post-pet-section" id="contact-form">

            <h2 class="section-header-title" style="margin-bottom: 8px;">
                Send Us a Message
            </h2>

            <?php if (!$loggedIn): ?>

                <div class="contact-login-notice">
                    <p>
                        You can view our contact details, social media links, FAQs, and office location freely. 
                        To send a direct message, please <a href="login.php?redirect=contact.php">log in</a> first.
                    </p>
                    <a href="login.php?redirect=contact.php" class="form-submit-btn" style="display: inline-block; text-decoration: none; text-align: center; max-width: 240px; margin: 0 auto;">
                        Log In
                    </a>
                </div>

            <?php else: ?>

                <p style="color: #64748b; font-size: 14px; margin-bottom: 20px;">
                    Fill out the form below and our team will get back to you as soon as possible.
                </p>

                <?php if (!empty($_SESSION['contact_success']) || !empty($_SESSION['contact_error'])): ?>
                    <div style="margin-bottom:16px;padding:12px 16px;border-radius:8px;font-size:14px;
                        <?= !empty($_SESSION['contact_success'])
                            ? 'background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;'
                            : 'background:#fef2f2;color:#991b1b;border:1px solid #fecaca;' ?>">
                        <?= htmlspecialchars($_SESSION['contact_success'] ?? $_SESSION['contact_error']) ?>
                    </div>
                    <?php unset($_SESSION['contact_success'], $_SESSION['contact_error']); ?>
                <?php endif; ?>       

                <form action="contact_submit.php" method="POST">

                    <div class="form-grid">

                        <!-- FULL NAME -->
                        <div class="form-group">
                            <label for="fullName">Full Name</label>
                            <input
                                type="text"
                                id="fullName"
                                name="full_name"
                                placeholder="e.g., Juan Dela Cruz"
                                required
                            >
                        </div>

                        <!-- EMAIL -->
                        <div class="form-group">
                            <label for="emailAddress">Email Address</label>
                            <input
                                type="email"
                                id="emailAddress"
                                name="email"
                                placeholder="e.g., juan@email.com"
                                required
                            >
                        </div>

                        <!-- CONTACT NUMBER -->
                        <div class="form-group">
                            <label for="contactNumber">Contact Number</label>
                            <input
                                type="text"
                                id="contactNumber"
                                name="contact_number"
                                placeholder="e.g., 0917 123 4567"
                            >
                        </div>

                        <!-- INQUIRY TYPE -->
                        <div class="form-group">
                            <label for="inquiryType">Inquiry Type</label>
                            <select id="inquiryType" name="inquiry_type">
                                <option value="general">General Inquiry</option>
                                <option value="adoption">Adoption Question</option>
                                <option value="rescue">Emergency Rescue Concern</option>
                                <option value="health">Health Drive / Vaccination</option>
                                <option value="partner">Shelter Partnership</option>
                                <option value="report">Report a Problem / Listing</option>
                            </select>
                        </div>

                        <!-- SUBJECT -->
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="subject">Subject</label>
                            <input
                                type="text"
                                id="subject"
                                name="subject"
                                placeholder="Brief summary of your message"
                            >
                        </div>

                        <!-- MESSAGE -->
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="message">Message</label>
                            <textarea
                                id="message"
                                name="message"
                                rows="5"
                                placeholder="Tell us how we can help. For rescue concerns, include the barangay and exact street location..."
                                required
                            ></textarea>
                        </div>

                    </div>

                    <button type="submit" class="form-submit-btn">
                        Send Message
                    </button>

                </form>

            <?php endif; ?>

        </section>


        <!-- FAQ -->
        <section class="layer-slider-section" id="faq-section">

            <h2 class="section-header-title">
                Frequently Asked Questions
            </h2>

            <div class="faq-list">

                <details class="faq-item">
                    <summary>How do I adopt a pet through Pawfinder?</summary>
                    <p>
                        Browse the Dogs, Cats, and Birds for Adoption sections,
                        open a pet's details, and send an adoption request.
                        You'll need to register an Adopter account first.
                    </p>
                </details>

                <details class="faq-item">
                    <summary>I found a stray animal. What should I do?</summary>
                    <p>
                        Use the "Report Stray Pet Sighting" form on the homepage
                        with the barangay and exact location.
                        If the animal is injured, call the emergency rescue hotline immediately.
                    </p>
                </details>

                <details class="faq-item">
                    <summary>Are the vaccination drives really free?</summary>
                    <p>
                        Yes. Free medicine and vaccination schedules are posted
                        in the Health Drives section, including the barangay,
                        date, time, and services offered.
                    </p>
                </details>

                <details class="faq-item">
                    <summary>How can my shelter or rescue group become a partner?</summary>
                    <p>
                        Select "Shelter Partnership" in the contact form above
                        and share your organization's details.
                        Our team will review and verify your application.
                    </p>
                </details>

            </div>

        </section>


        <!-- MAP -->
        <section class="layer-slider-section" id="map-section">

            <h2 class="section-header-title">
                Find Us
            </h2>

            <div class="map-wrapper">
                <iframe
                    title="Pawfinder office location - Clark College"
                    src="https://www.google.com/maps?q=15.1762708,120.5869172&z=17&output=embed"
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade"
                    allowfullscreen>
                </iframe>
            </div>

        </section>

    </main>

</body>
</html>