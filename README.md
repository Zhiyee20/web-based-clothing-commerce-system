# Web-Based Clothing Commerce System  
_Final Year Project (FYP)_

## Overview
This project is a **Web-Based Clothing Commerce System** developed as a Final Year Project.  
The system aims to enhance the online shopping experience by integrating **core e-commerce functionalities** with **AI-powered visual search** and **intelligent promotion and reward mechanisms**.

The system adopts a hybrid architecture where **PHP** is used for core business logic and system operations, while **Python-based AI modules** handle visual search and data processing tasks.

---

## Project Type
- Web Application  
- Final Year Project (Completed)  
- Group Project  

---

## Tech Stack

**Backend & System**
- PHP  
- MySQL (phpMyAdmin)  
- Composer  

**AI / Machine Learning**
- Python  
- YOLO (Object Detection)  
- Image Embedding & Similarity Matching  

**Frontend**
- HTML  
- CSS  
- JavaScript  

---

## My Role & Responsibilities
This is a **group-based Final Year Project**.  
My primary responsibilities focused on **backend development and AI-related modules**, including:

- **Visual Search Engine**
  - Object detection using YOLO
  - Image embedding generation
  - Similarity-based product search

- **Product Catalog Management**
  - Product data handling
  - Image-based product association
  - Category and image processing logic

- **Promotion Management**
  - Campaign and promotion logic
  - Discount application and validation rules

- **Reward Points System**
  - Points accumulation logic
  - Reward calculation and redemption flow

- **System Security**
  - Authentication and authorization logic
  - Password hashing
  - Input validation and access control

---

## System Architecture (High-Level)
- **PHP Backend**
  - Handles authentication, product management, promotions, rewards, and order-related logic
- **Python AI Services**
  - Perform visual search, image preprocessing, embedding generation, and similarity matching
- **Database (MySQL)**
  - Stores user data, product catalog, promotions, rewards, and system records

A detailed architecture explanation is provided in `ARCHITECTURE.md`.

---

## Visual Search Workflow
1. User uploads or selects a product image  
2. Image is processed using **YOLO** for object detection  
3. Extracted features are converted into embeddings  
4. Similarity matching is performed against the product catalog  
5. Relevant products are returned to the user  

---

## Repository Structure (Simplified)

admin/                 # Admin-related backend logic  
security/              # Authentication & security handling  
user/                  # User-side logic  

YOLO/                  # Object detection module  
category_embeddings/   # Image embedding data  
data_preprocess/       # Data preprocessing scripts  
search/                # Visual search logic  

assets/                # UI demo assets (limited)  
uploads/               # Demo product images only  

index.php              # Application entry point  
app.py                 # Python AI service entry
