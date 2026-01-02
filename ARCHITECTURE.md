# System Architecture

## Overview
This project adopts a hybrid web–AI architecture that combines a traditional
PHP-based e-commerce system with Python-based AI modules to enhance product
discovery and user interaction.

The architecture is designed to separate core business logic from
computationally intensive AI processing, ensuring maintainability,
scalability, and clear responsibility boundaries.

---

## High-Level Architecture
The system consists of three main layers:

1. Web Application Layer (PHP)
2. AI Processing Layer (Python)
3. Data Storage Layer (MySQL and Media Assets)

Each layer operates independently but communicates through clearly defined
interfaces.

---

## 1. Web Application Layer (PHP)
The PHP backend is responsible for all core e-commerce operations and system
management tasks.

### Responsibilities
- User authentication and authorization
- Product catalog management
- Promotion and reward logic
- Order and transaction handling
- Security enforcement (input validation and access control)

### Key Components
- admin/ – Admin-side management features
- user/ – User-facing features and interactions
- security/ – Authentication, authorization, and validation logic
- index.php – Application entry point
- config.php – Database connection and system configuration (credentials excluded)

The PHP layer acts as the system controller, coordinating user actions and
request flow.

---

## 2. AI Processing Layer (Python)
The AI layer enhances the system with visual-based product search.

### Responsibilities
- Object detection using YOLO
- Image preprocessing and feature extraction
- Image embedding generation
- Similarity-based product matching

### Key Components
- YOLO/ – Object detection model and related scripts
- data_preprocess/ – Image preprocessing utilities
- category_embeddings/ – Pre-generated image embeddings
- search/ – Similarity matching and visual search logic
- app.py – AI service entry script

This layer is designed to be decoupled from the PHP backend, allowing AI models
to be updated or replaced without impacting core system functionality.

---

## 3. Data Storage Layer

### Database (MySQL)
The MySQL database stores structured system data, including:
- User accounts
- Product information
- Promotions and reward records
- Order and transaction data

Database access is handled exclusively through the PHP backend using PDO.

### Media Assets
- uploads/ – Limited demo product images for UI presentation
- assets/ – UI-related assets such as banners and demo visuals
- img/ and img_category/ – Demo-only images for catalog and AI showcase

Demo media is intentionally limited to avoid repository bloat and privacy risks.

---

## Visual Search Workflow
1. A product image is uploaded or selected by the user
2. The image is processed by the YOLO model to identify key objects
3. Extracted features are converted into embeddings
4. Similarity comparison is performed against stored product embeddings
5. Matching products are returned to the web application

This workflow enables image-based product discovery, complementing traditional
keyword search.

---

## Security Considerations
- Database credentials and secrets are excluded from the public repository
- Input validation is enforced at the backend level
- Role-based access control is applied to admin and user modules
- Error messages are generalized to avoid information leakage

Further security-related details are documented in SECURITY_NOTES.md.

---

## Design Rationale
- Separation of concerns: PHP handles business logic; Python handles AI tasks
- Scalability: AI components can be scaled independently
- Maintainability: Clear module boundaries reduce system coupling
- Security awareness: Sensitive data is never committed to the repository

---

## Summary
This architecture enables a robust and extensible e-commerce platform by
combining a stable web backend with advanced AI capabilities. The modular design
supports future enhancements such as improved recommendation models, additional
AI services, or external integrations without major system restructuring.
