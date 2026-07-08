/*
For a professional SaaS customer area, MemberPress often wins for ease-of-use and stability, while PMS Pro is the power-user/developer's choice for ultimate customization.
*/


// https://wordpress.org/plugins/profile-builder/
// https://wordpress.org/plugins/user-registration/
// https://memberpress.com/
// https://wordpress.org/plugins/paid-member-subscriptions/



### **Implementation Plan: The Technical & Strategic Synthesis**

**Objective:** To build the defined SaaS onboarding system by executing a precise, three-phase plan that leverages the advanced, synergistic capabilities of WS Forms Pro, Greenshift Pro, and Paid Member Subscriptions (PMS) Pro.

---

### **Phase 1: Architecting the Dynamic Onboarding Experience**

**Challenge:** How to create a multi-step form that not only changes its own fields but also displays rich, contextual content based on user selections—a feature that standard form builders struggle with.

**Technical Strategy:** We will use a "Shortcode Injection" method. WS Form will serve as the logic engine, while Greenshift will act as the content provider.

*   **Step 1: Build Reusable Content Modules (Greenshift Pro)**
    *   For each piece of dynamic contextual content (e.g., "Enterprise Features," "Service X Details"), create a "Reusable Template" in Greenshift.
    *   Design these modules with the full power of Greenshift's blocks. The output will be a library of shortcodes, e.g., `[greenshift_template id="1"]`, `[greenshift_template id="2"]`. This modularizes the content.

*   **Step 2: Construct the Conditional Form Structure (WS Form Pro)**
    *   Build the multi-step flow using WS Form's "Tabs" and provide back-and-forth navigation using the "Navigation" element.
    *   At each step where dynamic content is needed, insert multiple **"HTML" fields**.
    *   Place a single Greenshift shortcode into each HTML field.
    *   Apply WS Form's **Conditional Logic** to the *HTML fields themselves*. This is the core technical solution. The logic will be, for example: "Show HTML Field A (containing shortcode #1) IF 'Account Type' is 'Enterprise'," and "Show HTML Field B (containing shortcode #2) IF 'Account Type' is 'Freelancer'."
    *   **Outcome:** This creates a seamless experience where the form itself dynamically renders entire, pre-designed content blocks from Greenshift based on the user's real-time input.

---

### **Phase 2: Engineering the Secure and Personalized Handoff**

**Challenge:** How to transition the user from the information-gathering phase (WS Form) to the payment phase (PMS) without losing context or forcing the user to re-enter data, ensuring they land on the correct recurring plan.

**Technical Strategy:** We will use a "Conditional Redirect with URL Parameters" method. This makes the handoff intelligent and frictionless.

*   **Step 1: Establish a Checkout Endpoint for Each Plan (PMS Pro)**
    *   Create each recurring subscription plan ("Basic," "Pro," etc.) within PMS.
    *   This action automatically generates a unique, stable URL for each plan's checkout page (e.g., `/subscription/pro/`). These are our fixed targets.

*   **Step 2: Configure the "Smart Router" (WS Form Pro)**
    *   In the WS Form "Actions" panel, create a separate **"Redirect" action for each potential subscription outcome**.
    *   Apply **Conditional Logic** to each redirect action. For example: "Fire this redirect IF 'Calculated Plan' field IS 'Pro'."
    *   Construct the redirect URL to include PMS's documented pre-fill parameters, using WS Form's variables to populate them.
        *   **Technical Detail:** The URL will be `https://yourdomain.com/subscription/pro/?pms_email=#field(101)&pms_first_name=#field(102)`. This syntax is the precise, evidence-based link between the two plugins.
    *   **Outcome:** The user's journey is seamlessly continued. They are directed to a checkout page that already knows who they are and which plan they are there to purchase.

---

### **Phase 3: Implementing the Custom Dashboard and Lifecycle Management**

**Challenge:** How to provide a fully branded, custom-designed user dashboard that still contains the critical, dynamic subscription management functions (like "update card" or "cancel").

**Technical Strategy:** We will use a "Layout Decoupling" approach. Greenshift/Gutenberg will control the page's visual structure, while PMS will provide the functional components via shortcodes.

*   **Step 1: Design the Dashboard Shell (Greenshift Pro & Gutenberg)**
    *   Create a new WordPress page that will serve as the main dashboard.
    *   Using Greenshift and the native block editor, build the complete visual layout—columns, branded headers, custom menus, links to SaaS tools, etc. This page is the "shell."

*   **Step 2: Inject Functional Components (PMS Pro Shortcodes)**
    *   Within the "shell," place PMS's functional shortcodes (`[pms-subscriptions]`, `[pms-edit-profile]`, etc.) inside standard "Shortcode" blocks.
    *   This renders the live, user-specific account data within your custom-designed layout, perfectly separating presentation from function.

*   **Step 3: (Optional but Recommended) Implement Custom Page Routing (Custom PHP)**
    *   **Challenge:** By default, PMS shortcode actions (like clicking "Edit Profile") might try to reload a generic page. To maintain the custom feel, we need to ensure all links point to our custom-designed pages.
    *   **Technical Solution:** Add a PHP snippet to your `functions.php` file that uses PMS's documented filters (e.g., `pms_get_page_url_edit-profile`). This code intercepts PMS's URL generation and forces it to use the slugs of your custom-built pages.
    *   **Outcome:** This final piece of custom development ensures a completely seamless and branded user experience throughout the entire account management lifecycle, fulfilling the "highly customizable dashboard" requirement at a professional level.
